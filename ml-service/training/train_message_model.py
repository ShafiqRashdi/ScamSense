"""Train the TF-IDF + Logistic Regression message baseline.

    python training/train_message_model.py --data training/data/messages.csv

The `ml-endpoint` skill requires this baseline before any DistilBERT fine-tune,
so that the transformer has something to beat. If it does not clearly beat this,
that is a legitimate Chapter 4 finding rather than a failure.

## Splitting by template, not at random

The corpus in use is templated synthetic data. Grouping by template is not a
refinement here, it is the difference between a meaningful number and a
meaningless one — a random split puts the same template in train and test, and
the model scores by recognising the template rather than by reading Malay.

`--split-strategy template` is therefore the default. It groups on
`text_preprocess.template_key`, the same normalisation used at serving time.

## Reporting

Every figure this script produces on a synthetic corpus must be reported as
"F1 on a synthetic template corpus", never as ScamSense's message-detection
accuracy. The script prints that qualifier itself and writes it into
metrics.json, so a number cannot be lifted out of context by accident.
"""

from __future__ import annotations

import argparse
import json
import platform
import random
import sys
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    average_precision_score,
    classification_report,
    confusion_matrix,
    precision_recall_curve,
    roc_auc_score,
)
from sklearn.pipeline import Pipeline

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.text_preprocess import normalise_lower, template_key  # noqa: E402
from training.train_url_model import (  # noqa: E402
    LABEL_ALIASES,
    RECALL_BETA,
    Split,
    make_split,
)

SEED = 42
F1_TARGET = 0.85

#: Stamped into metrics.json so a figure cannot be quoted without its caveat.
SYNTHETIC_QUALIFIER = (
    "F1 on a synthetic template corpus. This is NOT ScamSense's message-detection "
    "accuracy on real Malaysian messages. The corpus is generated from a small "
    "number of templates; see ml-service/training/DATASETS.md."
)


@dataclass(frozen=True)
class CorpusShape:
    """What the corpus actually contains, as opposed to how many rows it has."""

    rows: int
    templates: int
    rows_on_repeated_templates: int
    largest_template_rows: int

    @property
    def effective_n(self) -> int:
        """Independent examples. For templated data this is the template count."""
        return self.templates


def load_messages(path: Path) -> pd.DataFrame:
    if not path.exists():
        raise SystemExit(f"Dataset not found: {path}\nThis script does not generate data.")

    frame = pd.read_csv(path)

    missing = {"text", "label"} - set(frame.columns)
    if missing:
        raise SystemExit(f"Dataset is missing required column(s): {sorted(missing)}")

    frame = frame.dropna(subset=["text", "label"])
    frame["text"] = frame["text"].astype(str).str.strip()
    frame = frame[frame["text"] != ""]

    normalised = frame["label"].astype(str).str.strip().str.lower().map(LABEL_ALIASES)
    unknown = sorted(set(frame.loc[normalised.isna(), "label"].astype(str)))
    if unknown:
        raise SystemExit(f"Unrecognised label value(s): {unknown}")
    frame["label"] = normalised.astype(int)

    frame = frame.drop_duplicates(subset=["text"]).reset_index(drop=True)
    frame["template"] = frame["text"].map(template_key)

    if frame["label"].nunique() < 2:
        raise SystemExit("Dataset contains only one class; cannot train.")

    return frame


def describe_corpus(frame: pd.DataFrame) -> CorpusShape:
    counts = frame["template"].value_counts()

    return CorpusShape(
        rows=len(frame),
        templates=int(frame["template"].nunique()),
        rows_on_repeated_templates=int(frame["template"].map(counts).gt(1).sum()),
        largest_template_rows=int(counts.iloc[0]) if len(counts) else 0,
    )


def choose_threshold(y_val: np.ndarray, probabilities: np.ndarray) -> float:
    precision, recall, thresholds = precision_recall_curve(y_val, probabilities)
    precision, recall = precision[:-1], recall[:-1]

    denominator = (RECALL_BETA**2 * precision) + recall
    with np.errstate(divide="ignore", invalid="ignore"):
        f_beta = np.where(
            denominator > 0,
            (1 + RECALL_BETA**2) * precision * recall / denominator,
            0.0,
        )

    if len(thresholds) == 0:
        return 0.5

    return float(thresholds[int(np.argmax(f_beta))])


def evaluate(y_true: np.ndarray, probabilities: np.ndarray, threshold: float) -> dict[str, Any]:
    predictions = (probabilities >= threshold).astype(int)
    tn, fp, fn, tp = (int(v) for v in confusion_matrix(y_true, predictions, labels=[0, 1]).ravel())

    report = classification_report(
        y_true,
        predictions,
        labels=[0, 1],
        target_names=["legitimate", "scam"],
        output_dict=True,
        zero_division=0,
    )

    single_class = len(np.unique(y_true)) < 2

    return {
        "threshold": round(threshold, 6),
        "accuracy": report["accuracy"],
        "f1_scam": report["scam"]["f1-score"],
        "precision_scam": report["scam"]["precision"],
        "recall_scam": report["scam"]["recall"],
        "f1_legitimate": report["legitimate"]["f1-score"],
        "f1_macro": report["macro avg"]["f1-score"],
        "roc_auc": None if single_class else float(roc_auc_score(y_true, probabilities)),
        "average_precision": (
            None if single_class else float(average_precision_score(y_true, probabilities))
        ),
        "confusion_matrix": {
            "true_negative": tn,
            "false_positive": fp,
            "false_negative": fn,
            "true_positive": tp,
        },
        "support": {"legitimate": int((y_true == 0).sum()), "scam": int((y_true == 1).sum())},
    }


def print_report(title: str, metrics: dict[str, Any], templates: int | None = None) -> None:
    cm = metrics["confusion_matrix"]
    total = metrics["support"]["legitimate"] + metrics["support"]["scam"]

    print(f"\n{title}")
    print("=" * len(title))
    print(f"  rows                  {total}" + (f"  ({templates} templates)" if templates else ""))
    print(f"  threshold             {metrics['threshold']:.4f}")
    print(f"  accuracy              {metrics['accuracy']:.4f}")
    print(f"  F1 (scam)             {metrics['f1_scam']:.4f}")
    print(f"  precision (scam)      {metrics['precision_scam']:.4f}")
    print(f"  recall (scam)         {metrics['recall_scam']:.4f}")
    print(f"  F1 (legitimate)       {metrics['f1_legitimate']:.4f}")
    if metrics["roc_auc"] is not None:
        print(f"  ROC AUC               {metrics['roc_auc']:.4f}")
    print("\n  confusion matrix        predicted")
    print("                        legit    scam")
    print(f"    legit            {cm['true_negative']:>7} {cm['false_positive']:>7}")
    print(f"    scam             {cm['false_negative']:>7} {cm['true_positive']:>7}")


def build_pipeline(seed: int) -> Pipeline:
    """TF-IDF over word and character n-grams, then Logistic Regression.

    Character n-grams matter for Malay/English code-switching: they catch
    morphological variation ("sahkan", "pengesahan", "disahkan") that word
    features treat as unrelated tokens, and they degrade gracefully on the
    misspellings scam messages use to dodge keyword filters.

    class_weight="balanced" rather than resampling — oversampling before the
    split leaks, and after it duplicates rows the model overfits.
    """
    return Pipeline(
        [
            (
                "tfidf",
                TfidfVectorizer(
                    # A named import, not a lambda: scikit-learn pickles this by
                    # reference, so an unpicklable preprocessor produces an
                    # artefact that only fails when serving tries to load it.
                    preprocessor=normalise_lower,
                    analyzer="char_wb",
                    ngram_range=(2, 5),
                    min_df=2,
                    sublinear_tf=True,
                ),
            ),
            (
                "clf",
                LogisticRegression(
                    class_weight="balanced",
                    max_iter=2000,
                    random_state=seed,
                ),
            ),
        ]
    )


def report_template_leakage(split: Split, templates: np.ndarray) -> dict[str, int]:
    train_t = set(templates[split.train])
    test_t = set(templates[split.test])
    shared = train_t & test_t

    return {
        "templates_shared_train_test": len(shared),
        "test_rows_on_shared_templates": int(np.isin(templates[split.test], list(shared)).sum()),
        "test_rows_total": len(split.test),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, required=True)
    parser.add_argument("--version", default="1.0.0")
    parser.add_argument("--seed", type=int, default=SEED)
    parser.add_argument("--split-strategy", choices=["random", "template"], default="template")
    parser.add_argument(
        "--out", type=Path, default=Path(__file__).resolve().parent.parent / "models"
    )
    args = parser.parse_args()

    random.seed(args.seed)
    np.random.seed(args.seed)

    print(f"Loading {args.data}")
    frame = load_messages(args.data)
    shape = describe_corpus(frame)
    counts = frame["label"].value_counts().to_dict()

    print(f"  {shape.rows} rows: {counts.get(0, 0)} legitimate, {counts.get(1, 0)} scam")
    print(f"  {shape.templates} unique templates  <- the effective sample size")
    print(
        f"  {shape.rows_on_repeated_templates} rows "
        f"({shape.rows_on_repeated_templates / shape.rows:.1%}) sit on a repeated template; "
        f"the largest single template covers {shape.largest_template_rows} rows"
    )

    y = frame["label"].to_numpy()
    templates = frame["template"].to_numpy()

    print(f"\nSplitting, strategy={args.split_strategy}")
    split = make_split(y, args.seed, templates if args.split_strategy == "template" else None)
    leakage = report_template_leakage(split, templates)
    print(f"  train {len(split.train)}  val {len(split.val)}  test {len(split.test)}")
    print(
        f"  templates in both train and test: {leakage['templates_shared_train_test']} "
        f"({leakage['test_rows_on_shared_templates']} test rows)"
    )

    test_templates = len(set(templates[split.test]))
    if test_templates < 30:
        print(
            f"\n  WARNING: the test partition contains only {test_templates} distinct templates.\n"
            "  Every metric below is computed over that many independent examples, whatever\n"
            "  the row count says, so the confidence interval around it is very wide."
        )

    X_train = frame["text"].to_numpy()[split.train]
    X_val = frame["text"].to_numpy()[split.val]
    X_test = frame["text"].to_numpy()[split.test]

    print("\nTraining TF-IDF + Logistic Regression")
    model = build_pipeline(args.seed)
    model.fit(X_train, y[split.train])

    val_probabilities = model.predict_proba(X_val)[:, 1]
    threshold = choose_threshold(y[split.val], val_probabilities)
    val_metrics = evaluate(y[split.val], val_probabilities, threshold)
    print_report("Validation", val_metrics, len(set(templates[split.val])))

    test_probabilities = model.predict_proba(X_test)[:, 1]
    test_metrics = evaluate(y[split.test], test_probabilities, threshold)
    print_report("Test", test_metrics, test_templates)

    out_dir = args.out / f"tfidf-msg-v{args.version}"
    out_dir.mkdir(parents=True, exist_ok=True)

    joblib.dump(model, out_dir / "model.joblib")
    (out_dir / "split_indices.json").write_text(
        json.dumps({"seed": args.seed, "train": split.train, "val": split.val, "test": split.test}),
        encoding="utf-8",
    )
    (out_dir / "config.json").write_text(
        json.dumps(
            {
                "seed": args.seed,
                "model": "TfidfVectorizer(char_wb 2-5) + LogisticRegression",
                "class_weight": "balanced",
                "split_strategy": args.split_strategy,
                "dataset": str(args.data),
                "dataset_rows": shape.rows,
                "unique_templates": shape.templates,
                "python": platform.python_version(),
                "scikit_learn": sklearn.__version__,
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (out_dir / "metrics.json").write_text(
        json.dumps(
            {
                "model": f"tfidf-msg-v{args.version}",
                "trained_at": datetime.now(UTC).isoformat(),
                "REPORTING_QUALIFIER": SYNTHETIC_QUALIFIER,
                "split_strategy": args.split_strategy,
                "corpus": {
                    "rows": shape.rows,
                    "unique_templates": shape.templates,
                    "effective_n": shape.effective_n,
                    "test_templates": test_templates,
                },
                "template_leakage": leakage,
                "validation": val_metrics,
                "test": test_metrics,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    print(f"\nWrote {out_dir}")
    print(f"\n  {SYNTHETIC_QUALIFIER}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
