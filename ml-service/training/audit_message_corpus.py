"""Audit the collected Malaysian message corpus before any training.

Reports what the corpus actually contains — template diversity, split integrity
and class balance — so the numbers are on record before a model exists to
distract from them.

    python training/audit_message_corpus.py training/data/messages_my_with_metadata.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.text_preprocess import template_key  # noqa: E402

frame = pd.read_csv(sys.argv[1])
frame["skeleton"] = frame["text"].map(template_key)

print("=" * 70)
print("COLLECTED MALAYSIAN MESSAGE CORPUS — AUDIT")
print("=" * 70)

print(f"\nrows                     {len(frame)}")
print(f"exact duplicate texts    {int(frame['text'].duplicated().sum())}")

print("\n--- class balance ---")
counts = frame["label"].value_counts()
for label, n in counts.items():
    print(f"  {label:<12} {n:>4}  ({n / len(frame):.1%})")
minority = counts.idxmin()
print(f"  minority class: {minority} ({counts.min()}) -> class weights, not resampling")

print("\n--- TEMPLATE DIVERSITY (the number that killed the synthetic corpus) ---")
skeletons = frame["skeleton"].nunique()
print(
    f"  unique skeletons       {skeletons} of {len(frame)}  ({skeletons / len(frame):.1%} distinct)"
)
repeated = frame["skeleton"].value_counts()
rows_on_repeat = int(frame["skeleton"].map(repeated).gt(1).sum())
print(f"  skeletons used > once  {int((repeated > 1).sum())}")
print(f"  rows on a repeated skeleton {rows_on_repeat} ({rows_on_repeat / len(frame):.1%})")
print(f"  largest single skeleton     {int(repeated.iloc[0])} rows")
print("\n  for comparison, the synthetic corpus was 56 skeletons / 1732 rows (3.2% distinct)")

if repeated.iloc[0] > 1:
    print("\n  most repeated skeletons:")
    for text, n in repeated.head(5).items():
        if n > 1:
            print(f"    {n:>3}x  {text[:78]}")

if "split" in frame.columns:
    print("\n--- PRE-ASSIGNED SPLITS (to be preserved, not reshuffled) ---")
    print(frame["split"].value_counts().to_string())
    print("\n  class balance within each split:")
    print(pd.crosstab(frame["split"], frame["label"]).to_string())

    print("\n  skeleton overlap between splits (must be zero):")
    by_split = frame.groupby("split")["skeleton"].apply(set)
    names = list(by_split.index)
    worst = 0
    for i, a in enumerate(names):
        for b in names[i + 1 :]:
            shared = by_split[a] & by_split[b]
            worst = max(worst, len(shared))
            flag = "OK" if not shared else "LEAK"
            print(f"    {a:>10} n {b:<10} {len(shared):>3} shared   [{flag}]")
    print(
        f"\n  verdict: {'no template leakage across splits' if worst == 0 else 'LEAKAGE PRESENT'}"
    )

    test_skeletons = frame[frame["split"] == "test"]["skeleton"].nunique()
    print(f"\n  distinct skeletons in test: {test_skeletons}")
    if test_skeletons < 30:
        print("  WARNING: a test set with under 30 independent examples has a very wide CI")

for column in ("language", "scam_type", "source"):
    if column in frame.columns:
        print(f"\n--- {column} ---")
        vc = frame[column].value_counts(dropna=False)
        for value, n in vc.head(12).items():
            print(f"  {str(value)[:44]:<46} {n:>4}  ({n / len(frame):.1%})")
        if len(vc) > 12:
            print(f"  … and {len(vc) - 12} more")
        if column == "scam_type":
            print(f"  largest category is {vc.iloc[0] / len(frame):.1%} of the corpus")

print("\n" + "=" * 70)
