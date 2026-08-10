"""Train the Random Forest URL classifier.

Runnable end to end from a clean checkout, given a dataset:

    python training/train_url_model.py --data training/data/urls.csv

The dataset is a CSV with a `url` column and a `label` column, where the label
is 1 for phishing and 0 for legitimate. See training/DATASETS.md for the sources
this project uses and the provenance each one requires.

## Discipline this script enforces

**The split happens once and is written to disk.** `split_indices.json` records
exactly which rows landed in train, validation and test. Re-running with the
same seed reproduces it; the committed file means a reader can verify the test
set was never trained on rather than taking it on trust.

**The test set is opened once.** The decision threshold is chosen on the
validation set, never the test set. Tuning a threshold against the numbers you
then report is how an honest-looking F1 becomes meaningless, and it is the
first thing an examiner who knows the field will check.

**Recall on the phishing class outranks accuracy.** A missed scam costs a user
money; a false positive costs them ten seconds of doubt. The threshold search
optimises F-beta with beta > 1 to reflect that asymmetry rather than defaulting
to 0.5, and the chosen threshold is written into metrics.json so the serving
code uses the same one.

**Nothing is fabricated.** If the dataset is absent the script exits with an
error. It does not invent data, and no metric appears anywhere in this
repository that did not come out of a real run.
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
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import (
    average_precision_score,
    classification_report,
    confusion_matrix,
    fbeta_score,
    precision_recall_curve,
    roc_auc_score,
)
from sklearn.model_selection import train_test_split

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.url_features import (  # noqa: E402
    FEATURE_NAMES,
    extract_features,
    to_vector,
)

SEED = 42

#: Weighting recall above precision when choosing the decision threshold.
#: beta=2 makes recall four times as important as precision in the F-beta
#: score. See the module docstring for why.
RECALL_BETA = 2.0

#: The minimum F1 objective O1 is graded against (AGENTS.md).
F1_TARGET = 0.85


@dataclass(frozen=True)
class Split:
    """Row indices for each partition, so the split can be persisted."""

    train: list[int]
    val: list[int]
    test: list[int]


def set_seeds(seed: int = SEED) -> None:
    random.seed(seed)
    np.random.seed(seed)


def load_dataset(path: Path) -> pd.DataFrame:
    """Read and sanity-check the dataset.

    Duplicate URLs are dropped *before* splitting. Leaving them in would put
    the same URL in train and test, which inflates every reported metric — the
    single most common way a URL classifier reports a score it has not earned.
    """
    if not path.exists():
        raise SystemExit(
            f"Dataset not found: {path}\n\n"
            "This script does not generate data. Download a phishing URL "
            "dataset and record its provenance in training/DATASETS.md first.\n"
            "Expected CSV columns: url, label  (label: 1 = phishing, 0 = legitimate)"
        )

    frame = pd.read_csv(path)

    missing = {"url", "label"} - set(frame.columns)
    if missing:
        raise SystemExit(f"Dataset is missing required column(s): {sorted(missing)}")

    before = len(frame)
    frame = frame.dropna(subset=["url", "label"])
    frame["url"] = frame["url"].astype(str).str.strip()
    frame = frame[frame["url"] != ""]
    frame["label"] = frame["label"].astype(int)

    frame = frame.drop_duplicates(subset=["url"]).reset_index(drop=True)
    dropped = before - len(frame)

    if dropped:
        print(f"  dropped {dropped} blank or duplicate rows before splitting")

    if set(frame["label"].unique()) - {0, 1}:
        raise SystemExit("Labels must be 0 (legitimate) or 1 (phishing).")

    if frame["label"].nunique() < 2:
        raise SystemExit("Dataset contains only one class; cannot train.")

    return frame


def build_feature_matrix(urls: pd.Series) -> np.ndarray:
    """Extract features using the SAME module the Flask service imports.

    Training and serving sharing one implementation is what prevents the skew
    that silently degrades a deployed model.
    """
    return np.array([to_vector(extract_features(url)) for url in urls], dtype=np.float64)


def make_split(labels: np.ndarray, seed: int = SEED) -> Split:
    """Stratified 70/15/15, computed once."""
    indices = np.arange(len(labels))

    train_idx, holdout_idx = train_test_split(
        indices, test_size=0.30, stratify=labels, random_state=seed
    )
    val_idx, test_idx = train_test_split(
        holdout_idx, test_size=0.50, stratify=labels[holdout_idx], random_state=seed
    )

    return Split(
        train=sorted(int(i) for i in train_idx),
        val=sorted(int(i) for i in val_idx),
        test=sorted(int(i) for i in test_idx),
    )


def choose_threshold(y_val: np.ndarray, probabilities: np.ndarray) -> float:
    """Pick the decision threshold on the VALIDATION set.

    Maximises F-beta with beta=2, so recall on the phishing class is weighted
    above precision. Using the test set here would invalidate every number this
    script later reports.
    """
    precision, recall, thresholds = precision_recall_curve(y_val, probabilities)

    # precision_recall_curve returns one more point than it does thresholds.
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

    matrix = confusion_matrix(y_true, predictions, labels=[0, 1])
    tn, fp, fn, tp = (int(v) for v in matrix.ravel())

    report = classification_report(
        y_true,
        predictions,
        labels=[0, 1],
        target_names=["legitimate", "phishing"],
        output_dict=True,
        zero_division=0,
    )

    return {
        "threshold": round(threshold, 6),
        "accuracy": report["accuracy"],
        "f1_phishing": report["phishing"]["f1-score"],
        "precision_phishing": report["phishing"]["precision"],
        "recall_phishing": report["phishing"]["recall"],
        "f1_legitimate": report["legitimate"]["f1-score"],
        "f1_macro": report["macro avg"]["f1-score"],
        "fbeta2_phishing": float(
            fbeta_score(y_true, predictions, beta=RECALL_BETA, zero_division=0)
        ),
        "roc_auc": float(roc_auc_score(y_true, probabilities)),
        "average_precision": float(average_precision_score(y_true, probabilities)),
        "confusion_matrix": {
            "true_negative": tn,
            "false_positive": fp,
            "false_negative": fn,
            "true_positive": tp,
        },
        "support": {"legitimate": int((y_true == 0).sum()), "phishing": int((y_true == 1).sum())},
    }


def print_report(title: str, metrics: dict[str, Any]) -> None:
    cm = metrics["confusion_matrix"]

    print(f"\n{title}")
    print("=" * len(title))
    print(f"  threshold             {metrics['threshold']:.4f}")
    print(f"  accuracy              {metrics['accuracy']:.4f}")
    print(f"  F1 (phishing)         {metrics['f1_phishing']:.4f}")
    print(f"  precision (phishing)  {metrics['precision_phishing']:.4f}")
    print(f"  recall (phishing)     {metrics['recall_phishing']:.4f}   <- the one that matters")
    print(f"  F1 (legitimate)       {metrics['f1_legitimate']:.4f}")
    print(f"  ROC AUC               {metrics['roc_auc']:.4f}")
    print(f"  average precision     {metrics['average_precision']:.4f}")
    print("\n  confusion matrix")
    print("                 predicted")
    print("               legit   phish")
    print(f"    legit    {cm['true_negative']:>7} {cm['false_positive']:>7}")
    print(f"    phish    {cm['false_negative']:>7} {cm['true_positive']:>7}")
    print(f"\n    {cm['false_negative']} missed scams, {cm['false_positive']} false alarms")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, required=True, help="CSV with url,label columns")
    parser.add_argument("--version", default="1.0.0", help="Artefact version, e.g. 1.0.0")
    parser.add_argument("--seed", type=int, default=SEED)
    parser.add_argument("--n-estimators", type=int, default=300)
    parser.add_argument("--max-depth", type=int, default=None)
    parser.add_argument(
        "--out",
        type=Path,
        default=Path(__file__).resolve().parent.parent / "models",
        help="Directory to write the versioned artefact into",
    )
    args = parser.parse_args()

    set_seeds(args.seed)

    print(f"Loading {args.data}")
    frame = load_dataset(args.data)
    counts = frame["label"].value_counts().to_dict()
    print(f"  {len(frame)} rows: {counts.get(0, 0)} legitimate, {counts.get(1, 0)} phishing")

    print("Extracting features")
    X = build_feature_matrix(frame["url"])
    y = frame["label"].to_numpy()

    print("Splitting 70/15/15, stratified")
    split = make_split(y, args.seed)
    print(f"  train {len(split.train)}  val {len(split.val)}  test {len(split.test)}")

    X_train, y_train = X[split.train], y[split.train]
    X_val, y_val = X[split.val], y[split.val]
    X_test, y_test = X[split.test], y[split.test]

    print("Training Random Forest")
    model = RandomForestClassifier(
        n_estimators=args.n_estimators,
        max_depth=args.max_depth,
        # Phishing datasets are usually imbalanced. Class weights are preferred
        # over resampling: oversampling before the split leaks, and doing it
        # after still duplicates rows the trees then overfit.
        class_weight="balanced",
        n_jobs=-1,
        random_state=args.seed,
    )
    model.fit(X_train, y_train)

    print("Choosing the decision threshold on the VALIDATION set")
    val_probabilities = model.predict_proba(X_val)[:, 1]
    threshold = choose_threshold(y_val, val_probabilities)
    val_metrics = evaluate(y_val, val_probabilities, threshold)
    print_report("Validation", val_metrics)

    print("\nEvaluating on the frozen TEST set (opened once)")
    test_probabilities = model.predict_proba(X_test)[:, 1]
    test_metrics = evaluate(y_test, test_probabilities, threshold)
    print_report("Test (this is the number for Chapter 4)", test_metrics)

    importances = sorted(
        zip(FEATURE_NAMES, (float(v) for v in model.feature_importances_), strict=True),
        key=lambda pair: pair[1],
        reverse=True,
    )
    print("\nTop 10 features by importance")
    for name, importance in importances[:10]:
        print(f"  {importance:.4f}  {name}")

    out_dir = args.out / f"rf-url-v{args.version}"
    out_dir.mkdir(parents=True, exist_ok=True)

    joblib.dump(model, out_dir / "model.joblib")
    (out_dir / "feature_list.json").write_text(
        json.dumps({"features": FEATURE_NAMES, "count": len(FEATURE_NAMES)}, indent=2),
        encoding="utf-8",
    )
    (out_dir / "split_indices.json").write_text(
        json.dumps({"seed": args.seed, "train": split.train, "val": split.val, "test": split.test}),
        encoding="utf-8",
    )
    (out_dir / "config.json").write_text(
        json.dumps(
            {
                "seed": args.seed,
                "n_estimators": args.n_estimators,
                "max_depth": args.max_depth,
                "class_weight": "balanced",
                "recall_beta": RECALL_BETA,
                "dataset": str(args.data),
                "dataset_rows": len(frame),
                "python": platform.python_version(),
                "scikit_learn": sklearn.__version__,
                "numpy": np.__version__,
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (out_dir / "metrics.json").write_text(
        json.dumps(
            {
                "model": f"rf-url-v{args.version}",
                "trained_at": datetime.now(UTC).isoformat(),
                "validation": val_metrics,
                "test": test_metrics,
                "feature_importances": dict(importances),
                "notes": (
                    "Threshold selected on the validation set only. The test set "
                    "was evaluated once, after the threshold was fixed."
                ),
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    print(f"\nWrote {out_dir}")

    test_f1 = test_metrics["f1_phishing"]
    if test_f1 < F1_TARGET:
        print(
            f"\n  WARNING: test F1 on the phishing class is {test_f1:.4f}, "
            f"below the {F1_TARGET} required by objective O1.\n"
            "  Report this number as it stands. Do NOT tune against the test\n"
            "  set to raise it. Things worth trying, in order:\n"
            "    1. More and more varied training data, especially Malaysian\n"
            "       phishing URLs, which public datasets under-represent.\n"
            "    2. Check the legitimate class is not dominated by a handful of\n"
            "       domains — the model may be learning those, not phishing.\n"
            "    3. Add features: character n-grams over the host, or the\n"
            "       registrable-domain age if a source is available offline.\n"
            "    4. Tune n_estimators and max_depth on the VALIDATION set.\n"
        )
        return 1

    print(f"\n  Test F1 {test_f1:.4f} meets the {F1_TARGET} target for objective O1.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
