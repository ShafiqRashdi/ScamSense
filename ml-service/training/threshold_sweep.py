"""Report metrics across decision thresholds for a trained URL model.

The training script picks one threshold by maximising recall-weighted F-beta.
That is the right default for a scam checker, but it produces a lot of false
alarms, and the Phase 6 UI needs to know where the bands sit rather than
inheriting a single number.

Reads the frozen test split from the artefact — it does not re-split, so the
numbers here are on exactly the rows the model never saw.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from training.train_url_model import build_feature_matrix, load_dataset  # noqa: E402

artefact = Path(sys.argv[1])
data = Path(sys.argv[2])

model = joblib.load(artefact / "model.joblib")
split = json.loads((artefact / "split_indices.json").read_text(encoding="utf-8"))
metrics = json.loads((artefact / "metrics.json").read_text(encoding="utf-8"))

frame = load_dataset(data)
X = build_feature_matrix(frame["url"])
y = frame["label"].to_numpy()

test_idx = split["test"]
probabilities = model.predict_proba(X[test_idx])[:, 1]
y_test = y[test_idx]

chosen = metrics["test"]["threshold"]

print(f"artefact        {artefact.name}")
print(f"split strategy  {metrics['split_strategy']}")
print(f"test rows       {len(y_test):,}  ({int((y_test == 1).sum()):,} phishing)")
print(f"chosen threshold {chosen}\n")

header = (
    f"{'thresh':>7} {'F1':>7} {'prec':>7} {'recall':>7} {'missed':>8} {'falarms':>8} {'FPR':>7}"
)
print(header)
print("-" * len(header))

negatives = int((y_test == 0).sum())

for threshold in [0.10, chosen, 0.20, 0.30, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90]:
    predictions = (probabilities >= threshold).astype(int)

    tp = int(((predictions == 1) & (y_test == 1)).sum())
    fp = int(((predictions == 1) & (y_test == 0)).sum())
    fn = int(((predictions == 0) & (y_test == 1)).sum())

    precision = tp / (tp + fp) if tp + fp else 0.0
    recall = tp / (tp + fn) if tp + fn else 0.0
    f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
    marker = "  <- chosen" if threshold == chosen else ""

    print(
        f"{threshold:>7.3f} {f1:>7.4f} {precision:>7.4f} {recall:>7.4f} "
        f"{fn:>8,} {fp:>8,} {fp / negatives:>7.1%}{marker}"
    )

print("\nHow often each band would fire on the test set")
bands = pd.cut(
    probabilities,
    bins=[-0.001, 0.20, 0.60, 1.001],
    labels=["low", "medium", "high"],
)
summary = pd.DataFrame({"band": bands, "actual": y_test})
counts = summary.groupby("band", observed=False)["actual"].agg(["count", "mean"])
counts.columns = ["rows", "actual_phishing_rate"]
print(counts.to_string(float_format=lambda v: f"{v:.3f}"))
