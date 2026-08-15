"""Find features that encode which dataset a URL came from.

    python training/audit_url_artefacts.py training/data/urls_combined.csv

## The idea

`uses_https` was caught by hand, and the trailing-slash defect was caught by
accident. Both were the same thing: a feature that separates the *sources* a
corpus was assembled from rather than the *classes* it is meant to distinguish.
This script looks for the rest of them systematically instead of waiting for the
next accident.

The method is simple. Within a single label — so the class itself cannot be the
explanation — train a classifier to predict which source file each row came
from. Any feature that predicts source well is describing how the corpus was
built. If the sources also correlate with the label (Tranco is 100% legitimate,
for instance), that feature is a shortcut the real classifier can and will take.

A feature scoring high here is not automatically wrong: `is_suspicious_tld`
genuinely differs between a phishing feed and a top-sites list because the
underlying populations differ. The output is a ranked list of suspects for
human judgement, not a verdict.
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import roc_auc_score
from sklearn.model_selection import train_test_split

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.url_features import FEATURE_NAMES, extract_features  # noqa: E402

SEED = 42

frame = pd.read_csv(sys.argv[1])
if "source" not in frame.columns:
    raise SystemExit("This audit needs a `source` column to work with.")

frame["label"] = frame["label"].astype(str).str.lower()

print("=" * 74)
print("URL CORPUS — SYSTEMATIC ARTEFACT AUDIT")
print("=" * 74)
print("\nrows by source and label")
print(pd.crosstab(frame["source"], frame["label"]).to_string())

print("\nextracting features…")
matrix = np.array(
    [[extract_features(u)[n] for n in FEATURE_NAMES] for u in frame["url"].astype(str)],
    dtype=np.float64,
)

# ── 1. Which features separate sources within one label? ───────────────
for label in sorted(frame["label"].unique()):
    mask = (frame["label"] == label).to_numpy()
    sources = frame.loc[mask, "source"].to_numpy()

    if len(set(sources)) < 2:
        print(f"\n--- {label}: only one source, nothing to compare ---")
        continue

    print(f"\n{'=' * 74}")
    print(f"WITHIN LABEL '{label.upper()}' — can we tell the source from the features?")
    print("=" * 74)

    X, y = matrix[mask], sources
    X_tr, X_te, y_tr, y_te = train_test_split(X, y, test_size=0.3, random_state=SEED, stratify=y)

    clf = RandomForestClassifier(n_estimators=120, max_depth=12, random_state=SEED, n_jobs=-1)
    clf.fit(X_tr, y_tr)
    accuracy = clf.score(X_te, y_te)
    baseline = pd.Series(y_te).value_counts(normalize=True).max()

    print(f"\n  source-prediction accuracy {accuracy:.4f}   (majority baseline {baseline:.4f})")
    if accuracy > baseline + 0.15:
        print("  ** the features strongly encode which file a row came from **")

    print("\n  features most responsible, i.e. the artefact suspects:")
    ranked = sorted(
        zip(FEATURE_NAMES, clf.feature_importances_, strict=True),
        key=lambda p: p[1],
        reverse=True,
    )
    for name, importance in ranked[:8]:
        print(f"    {importance:.4f}  {name}")

# ── 2. Per-feature separation between the two classes' source mixes ────
print(f"\n{'=' * 74}")
print("PER-FEATURE: does this feature predict LABEL, or does it predict SOURCE?")
print("=" * 74)
print("\n  A feature with high source-AUC and high label-AUC is doing both jobs,")
print("  and cannot be trusted to be measuring phishing.\n")

label_y = (frame["label"] == "phishing").astype(int).to_numpy()
# Collapse sources into "was this row's source single-class?" — Tranco is
# legitimate-only, so a feature that identifies Tranco rows identifies
# legitimate rows for free.
single_class_sources = {
    s for s in frame["source"].unique() if frame.loc[frame["source"] == s, "label"].nunique() == 1
}
source_y = frame["source"].isin(single_class_sources).astype(int).to_numpy()

print(f"  single-class sources: {sorted(single_class_sources)}")
print(f"  rows from them: {int(source_y.sum()):,} of {len(frame):,}\n")

print(f"  {'feature':<40} {'label AUC':>10} {'source AUC':>11}   flag")
print("  " + "-" * 72)

rows = []
for i, name in enumerate(FEATURE_NAMES):
    column = matrix[:, i]
    if len(np.unique(column)) < 2:
        continue
    label_auc = roc_auc_score(label_y, column)
    source_auc = roc_auc_score(source_y, column)
    rows.append((name, max(label_auc, 1 - label_auc), max(source_auc, 1 - source_auc)))

for name, label_auc, source_auc in sorted(rows, key=lambda r: -r[2]):
    flag = ""
    if source_auc > 0.75:
        flag = "<-- ARTEFACT SUSPECT"
    elif source_auc > 0.65:
        flag = "<-- watch"
    print(f"  {name:<40} {label_auc:>10.4f} {source_auc:>11.4f}   {flag}")

print(f"\n{'=' * 74}")
print("Read this as a list of suspects, not a verdict. A feature can separate")
print("sources legitimately when the underlying populations genuinely differ.")
print("=" * 74)
