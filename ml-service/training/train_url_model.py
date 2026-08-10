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
from sklearn.model_selection import StratifiedGroupKFold, train_test_split

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.url_features import (  # noqa: E402
    FEATURE_NAMES,
    extract_features,
    parse,
    to_vector,
)

SEED = 42

#: Accepted spellings of each class. Public corpora disagree about whether the
#: positive class is 1, "phishing", "scam" or "malicious"; normalising here means
#: a new source can be dropped in without editing the CSV by hand, and a typo
#: raises rather than being silently coerced to a class.
LABEL_ALIASES: dict[str, int] = {
    "0": 0,
    "legitimate": 0,
    "legit": 0,
    "benign": 0,
    "ham": 0,
    "safe": 0,
    "1": 1,
    "phishing": 1,
    "phish": 1,
    "scam": 1,
    "malicious": 1,
    "spam": 1,
}

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

    normalised = frame["label"].astype(str).str.strip().str.lower().map(LABEL_ALIASES)
    unknown = sorted(set(frame.loc[normalised.isna(), "label"].astype(str)))
    if unknown:
        raise SystemExit(
            f"Unrecognised label value(s): {unknown}\n" f"Accepted: {sorted(LABEL_ALIASES)}"
        )
    frame["label"] = normalised.astype(int)

    frame = frame.drop_duplicates(subset=["url"]).reset_index(drop=True)
    dropped = before - len(frame)

    if dropped:
        print(f"  dropped {dropped} blank or duplicate rows before splitting")

    if frame["label"].nunique() < 2:
        raise SystemExit("Dataset contains only one class; cannot train.")

    return frame


def registrable_domains(urls: pd.Series) -> np.ndarray:
    """The registrable domain of each URL, used as the grouping key."""
    return np.array([parse(url).registrable_domain for url in urls.astype(str)])


def build_feature_matrix(urls: pd.Series, feature_names: list[str] | None = None) -> np.ndarray:
    """Extract features using the SAME module the Flask service imports.

    Training and serving sharing one implementation is what prevents the skew
    that silently degrades a deployed model.

    `feature_names` selects and orders the columns, defaulting to the full
    FEATURE_NAMES. Passing a subset supports ablation studies — and whatever
    subset is used is written to feature_list.json, so serving reconstructs the
    same vector rather than assuming the full set.
    """
    active = feature_names or FEATURE_NAMES

    if active == FEATURE_NAMES:
        return np.array([to_vector(extract_features(url)) for url in urls], dtype=np.float64)

    return np.array(
        [[extract_features(url)[name] for name in active] for url in urls],
        dtype=np.float64,
    )


def make_split(
    labels: np.ndarray,
    seed: int = SEED,
    groups: np.ndarray | None = None,
) -> Split:
    """Stratified 70/15/15, computed once.

    With `groups`, no group straddles two partitions. That matters enormously
    for URL corpora and is the difference between an honest generalisation
    estimate and a flattering one.

    Free hosting platforms — web.app, firebaseapp.com, weeblysite.com, repl.co,
    workers.dev — are abused at scale, so thousands of phishing URLs share a
    handful of registrable domains. Under a random split those domains appear in
    both train and test, and the model can score well by memorising "web.app is
    phishing" without having learned anything about phishing structure. The
    reported F1 is then a measure of how concentrated the corpus is, not of how
    well the classifier generalises to a domain it has never seen.

    Grouping by registrable domain removes that shortcut. The resulting number
    is lower and is the one worth defending.
    """
    indices = np.arange(len(labels))

    if groups is None:
        train_idx, holdout_idx = train_test_split(
            indices, test_size=0.30, stratify=labels, random_state=seed
        )
        val_idx, test_idx = train_test_split(
            holdout_idx, test_size=0.50, stratify=labels[holdout_idx], random_state=seed
        )
    else:
        # StratifiedGroupKFold keeps groups intact while holding the class
        # balance roughly constant. Seven folds gives ~14.3% each: take one for
        # test, one for validation, and the remaining five for training, which
        # lands close to the 70/15/15 the project standardises on.
        splitter = StratifiedGroupKFold(n_splits=7, shuffle=True, random_state=seed)
        folds = [test for _, test in splitter.split(indices, labels, groups)]
        test_idx = folds[0]
        val_idx = folds[1]
        train_idx = np.concatenate(folds[2:])

    return Split(
        train=sorted(int(i) for i in train_idx),
        val=sorted(int(i) for i in val_idx),
        test=sorted(int(i) for i in test_idx),
    )


def report_group_leakage(split: Split, groups: np.ndarray) -> dict[str, int]:
    """How many registrable domains straddle the train and test partitions.

    Printed for every run, including random-split runs, so the leakage is
    visible in the log rather than discovered by an examiner.
    """
    train_groups = set(groups[split.train])
    test_groups = set(groups[split.test])
    shared = train_groups & test_groups
    leaked_rows = int(np.isin(groups[split.test], list(shared)).sum())

    return {
        "domains_shared_train_test": len(shared),
        "test_rows_on_shared_domains": leaked_rows,
        "test_rows_total": len(split.test),
    }


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
        "--split-strategy",
        choices=["random", "domain"],
        default="domain",
        help=(
            "'domain' (default) keeps every registrable domain within one "
            "partition, which is the honest generalisation estimate. 'random' "
            "matches what most published work does and will score higher."
        ),
    )
    parser.add_argument(
        "--drop-feature",
        action="append",
        default=[],
        metavar="NAME",
        help=(
            "Exclude a feature from training. Repeatable. Use for ablation when a "
            "feature is suspected of reflecting how the dataset was assembled "
            "rather than a property of the URLs themselves."
        ),
    )
    parser.add_argument(
        "--out",
        type=Path,
        default=Path(__file__).resolve().parent.parent / "models",
        help="Directory to write the versioned artefact into",
    )
    args = parser.parse_args()

    unknown_features = sorted(set(args.drop_feature) - set(FEATURE_NAMES))
    if unknown_features:
        raise SystemExit(
            f"Unknown feature(s) to drop: {unknown_features}\nAvailable: {FEATURE_NAMES}"
        )

    active_features = [name for name in FEATURE_NAMES if name not in args.drop_feature]
    if not active_features:
        raise SystemExit("Cannot drop every feature.")

    set_seeds(args.seed)

    print(f"Loading {args.data}")
    frame = load_dataset(args.data)
    counts = frame["label"].value_counts().to_dict()
    print(f"  {len(frame)} rows: {counts.get(0, 0)} legitimate, {counts.get(1, 0)} phishing")

    if args.drop_feature:
        print(f"Ablation: dropping {sorted(args.drop_feature)}")
    print(f"Extracting features ({len(active_features)} of {len(FEATURE_NAMES)})")
    X = build_feature_matrix(frame["url"], active_features)
    y = frame["label"].to_numpy()

    groups = registrable_domains(frame["url"])
    print(f"  {len(set(groups)):,} unique registrable domains")

    print(f"Splitting 70/15/15, stratified, strategy={args.split_strategy}")
    split = make_split(y, args.seed, groups if args.split_strategy == "domain" else None)
    print(f"  train {len(split.train)}  val {len(split.val)}  test {len(split.test)}")

    leakage = report_group_leakage(split, groups)
    share = leakage["test_rows_on_shared_domains"] / max(leakage["test_rows_total"], 1)
    print(
        f"  domains in both train and test: {leakage['domains_shared_train_test']:,} "
        f"({leakage['test_rows_on_shared_domains']:,} test rows, {share:.1%})"
    )
    if args.split_strategy == "random" and share > 0.10:
        print(
            "  NOTE: a substantial share of the test set sits on domains the model\n"
            "  also saw in training. The score below is therefore optimistic. Run\n"
            "  with --split-strategy domain for the number worth reporting."
        )

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
        zip(active_features, (float(v) for v in model.feature_importances_), strict=True),
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
        json.dumps(
            {
                "features": active_features,
                "count": len(active_features),
                "dropped": sorted(args.drop_feature),
            },
            indent=2,
        ),
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
                "split_strategy": args.split_strategy,
                "dataset": str(args.data),
                "dataset_rows": len(frame),
                "unique_registrable_domains": len(set(groups)),
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
                "split_strategy": args.split_strategy,
                "group_leakage": leakage,
                "validation": val_metrics,
                "test": test_metrics,
                "feature_importances": dict(importances),
                "notes": (
                    "Threshold selected on the validation set only. The test set "
                    "was evaluated once, after the threshold was fixed. "
                    f"Split strategy: {args.split_strategy}. Under 'random', "
                    "registrable domains appear in both train and test and the "
                    "score is optimistic; 'domain' is the generalisation estimate."
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
