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

#: Ceiling on the TF-IDF vocabulary. At 528 rows the corpus produces far fewer
#: than this, so the cap is currently inert; it exists so that growing the
#: corpus cannot quietly grow the served artefact without anyone deciding to.
MAX_TFIDF_FEATURES = 200_000

#: Stamped into metrics.json so a figure cannot be quoted without its caveat.
SYNTHETIC_QUALIFIER = (
    "F1 on a synthetic template corpus. This is NOT ScamSense's message-detection "
    "accuracy on real Malaysian messages. The corpus is generated from a small "
    "number of templates; see ml-service/training/DATASETS.md."
)


def build_qualifier(frame: pd.DataFrame, shape: CorpusShape) -> str:
    """The sentence any figure from this run must be quoted with.

    Derived from what the corpus actually contains rather than hardcoded, so a
    number cannot be lifted out of metrics.json and attributed to a corpus it
    did not come from. The row count, the class balance and the minority class
    all appear, because "F1 0.9" means something quite different on 528 rows
    with an imbalanced minority class than it does on 100,000 balanced ones.
    """
    sources = set(frame["source"].astype(str)) if "source" in frame.columns else set()
    counts = frame["label"].value_counts()
    n_legit = int(counts.get(0, 0))
    n_scam = int(counts.get(1, 0))
    minority = "legitimate" if n_legit <= n_scam else "scam"
    minority_n = min(n_legit, n_scam)

    if sources == {"collected_my"}:
        return (
            f"F1 on a {shape.rows}-message collected Malaysian corpus "
            f"({shape.templates} distinct templates, {shape.templates / shape.rows:.0%} distinct). "
            f"Real collected SMS/WhatsApp, redacted at source. Class balance is uneven: "
            f"{minority} is the smaller class at {minority_n}. "
            "This is a small corpus — treat the confidence interval as wide, and do not "
            "present this figure as equivalent to one measured on a large public dataset."
        )

    if sources and "collected_my" in sources:
        return (
            f"F1 on a {shape.rows}-message MIXED corpus (collected Malaysian plus other "
            f"sources: {sorted(sources)}). Provenance must be reported separately; see "
            "ml-service/training/DATASETS.md."
        )

    return SYNTHETIC_QUALIFIER


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

    # Deduplication happens before any split is read, so it can silently change
    # the composition of a split that was constructed and verified on the file
    # as written. At 528 unique rows this is a no-op; once collection batches
    # are being merged, duplicates become likely and a silent change to the
    # partitions would make the reported figure incomparable with the corpus
    # author's. Report it rather than let it pass unseen.
    before = len(frame)
    frame = frame.drop_duplicates(subset=["text"]).reset_index(drop=True)
    dropped = before - len(frame)
    if dropped:
        print(f"  NOTE: dropped {dropped} duplicate row(s) of {before} before splitting.")
        if "split" in frame.columns:
            print("        The shipped `split` column now covers a different set of rows")
            print("        than when it was constructed. Re-verify it before reporting.")

    frame["template"] = frame["text"].map(template_key)

    if frame["label"].nunique() < 2:
        raise SystemExit("Dataset contains only one class; cannot train.")

    return frame


def split_from_column(frame: pd.DataFrame) -> Split:
    """Use a `split` column that already exists in the data.

    For a deliberately constructed split — stratified, checked, and shipped with
    the corpus — recomputing one would discard that work and produce a different
    partition, making the reported figure incomparable with anything the corpus
    author measured.

    Leakage is reported rather than silently corrected: if the given split has
    templates in common across partitions, the caller is told, and the metric is
    additionally computed on the leak-free subset so both numbers are visible.
    """
    if "split" not in frame.columns:
        raise SystemExit("--split-strategy given requires a `split` column.")

    aliases = {
        "train": "train",
        "training": "train",
        "val": "val",
        "valid": "val",
        "validation": "val",
        "dev": "val",
        "test": "test",
        "holdout": "test",
    }
    normalised = frame["split"].astype(str).str.strip().str.lower().map(aliases)

    unknown = sorted(set(frame.loc[normalised.isna(), "split"].astype(str)))
    if unknown:
        raise SystemExit(f"Unrecognised split value(s): {unknown}")

    partitions = {
        name: [int(i) for i in frame.index[normalised == name]] for name in ("train", "val", "test")
    }

    for name, rows in partitions.items():
        if not rows:
            raise SystemExit(f"The given split has no rows in '{name}'.")

    report_given_split_balance(frame, partitions)

    return Split(train=partitions["train"], val=partitions["val"], test=partitions["test"])


#: How far a partition's scam rate may drift from the corpus rate before the
#: split stops being meaningfully stratified. Ten points is loose enough not to
#: fire on the rounding a small partition inevitably produces, and tight enough
#: to catch a partition that was assigned without stratifying.
STRATIFICATION_TOLERANCE = 0.10


def report_given_split_balance(frame: pd.DataFrame, partitions: dict[str, list[int]]) -> None:
    """Check that a shipped split is actually stratified, and say so either way.

    A given split is trusted not to be reshuffled, which means nothing else in
    this script will notice if it is skewed. Appending rows to a corpus and
    hand-assigning their partition is exactly how a split stops being
    stratified, and the symptom — a metric that moved for no visible reason —
    is very hard to trace back here.

    This warns rather than exits: the corpus author may have had a reason, and
    silently refusing to train on their data is worse than telling them what
    the balance is and letting them decide.
    """
    overall = float(frame["label"].mean())
    print(f"  class balance: corpus {overall:.1%} scam")

    for name in ("train", "val", "test"):
        rows = partitions[name]
        rate = float(frame["label"].iloc[rows].mean())
        drift = abs(rate - overall)
        flag = "  <-- DRIFT" if drift > STRATIFICATION_TOLERANCE else ""

        print(f"    {name:<6} {len(rows):>5} rows  {rate:.1%} scam  ({drift:+.1%}){flag}")

        if drift > STRATIFICATION_TOLERANCE:
            print(
                f"        WARNING: '{name}' is {drift:.1%} from the corpus rate. "
                "This split is not stratified."
            )


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
    """TF-IDF over character n-grams, then Logistic Regression.

    Character n-grams only — there is no word analyser. Characters are the right
    unit for Malay/English code-switching: they catch morphological variation
    ("sahkan", "pengesahan", "disahkan") that word features treat as unrelated
    tokens, and they degrade gracefully on the misspellings scam messages use to
    dodge keyword filters.

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
                    # Uncapped, char_wb 2-5 grams grow roughly linearly with the
                    # corpus, and so does the artefact the Flask service loads at
                    # every cold start. The cap bounds that at a size that still
                    # comfortably exceeds the vocabulary a few thousand messages
                    # produce, so it does not bind today and does not need
                    # revisiting when the corpus reaches its target.
                    max_features=MAX_TFIDF_FEATURES,
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
    parser.add_argument(
        "--split-strategy",
        choices=["random", "template", "given"],
        default="template",
        help=(
            "'given' preserves a `split` column already present in the data. Use "
            "it when the corpus ships a split that was constructed deliberately "
            "and must not be reshuffled."
        ),
    )
    parser.add_argument(
        "--out", type=Path, default=Path(__file__).resolve().parent.parent / "models"
    )
    args = parser.parse_args()

    random.seed(args.seed)
    np.random.seed(args.seed)

    print(f"Loading {args.data}")
    frame = load_messages(args.data)
    shape = describe_corpus(frame)
    qualifier = build_qualifier(frame, shape)
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
    if args.split_strategy == "given":
        split = split_from_column(frame)
        print("  using the split shipped with the corpus; not reshuffled")
    else:
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

    # A secondary metric on the test rows whose template was never seen in
    # train or validation.
    #
    # The primary figure above uses the split exactly as shipped, which is the
    # honest thing to report. But if some test templates also appear in
    # training, part of that figure measures recall of a memorised pattern
    # rather than generalisation, so the leak-free subset bounds it from below.
    # Reporting both is more informative than arguing about which is correct.
    seen_templates = set(templates[split.train]) | set(templates[split.val])
    clean_rows = [i for i in split.test if templates[i] not in seen_templates]
    leaked_rows = len(split.test) - len(clean_rows)

    clean_metrics = None
    if leaked_rows:
        print(
            f"\n  NOTE: {leaked_rows} of {len(split.test)} test rows "
            f"({leaked_rows / len(split.test):.1%}) sit on a template also present in "
            "train or validation."
        )
        if clean_rows and len(set(y[clean_rows])) > 1:
            clean_probabilities = model.predict_proba(frame["text"].to_numpy()[clean_rows])[:, 1]
            clean_metrics = evaluate(y[clean_rows], clean_probabilities, threshold)
            print_report(
                "Test — leak-free subset only",
                clean_metrics,
                len(set(templates[clean_rows])),
            )

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
                "REPORTING_QUALIFIER": qualifier,
                "split_strategy": args.split_strategy,
                "corpus": {
                    "rows": shape.rows,
                    "unique_templates": shape.templates,
                    "effective_n": shape.effective_n,
                    "test_templates": test_templates,
                    "sources": (
                        sorted(set(frame["source"].astype(str)))
                        if "source" in frame.columns
                        else ["unknown"]
                    ),
                    "class_counts": {
                        "legitimate": int((frame["label"] == 0).sum()),
                        "scam": int((frame["label"] == 1).sum()),
                    },
                },
                "template_leakage": leakage,
                "validation": val_metrics,
                "test": test_metrics,
                # The leak-free subset, when the given split has overlap. Null
                # when there is none, so its absence is unambiguous.
                "test_leak_free_subset": clean_metrics,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    print(f"\nWrote {out_dir}")
    print(f"\n  REPORT THIS AS:\n  {qualifier}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
