"""Combine the public URL corpus with the collected Malaysian URLs.

    python training/build_url_corpus.py

Writes training/data/urls_combined.csv with a `source` column preserved on
every row, so provenance survives into the artefact audit, the split, and the
results write-up. AGENTS.md requires the collected Malaysian data to stay
distinguishable from the public datasets at every stage.
"""

from __future__ import annotations

import sys
from pathlib import Path

import pandas as pd

DATA = Path(__file__).resolve().parent / "data"

public = pd.read_csv(DATA / "urls.csv")
collected = pd.read_csv(DATA / "legit_urls_my.csv")

if "source" not in public.columns:
    public["source"] = "public_unknown"
collected["source"] = "collected_my"

before = len(public) + len(collected)
combined = pd.concat([public, collected], ignore_index=True)

combined["url"] = combined["url"].astype(str).str.strip()
combined["label"] = combined["label"].astype(str).str.strip().str.lower()

# Deduplicate on the URL, keeping the collected row when a URL appears in both:
# a hand-verified Malaysian entry is better evidence than a bulk list entry.
combined["_priority"] = (combined["source"] == "collected_my").astype(int)
combined = (
    combined.sort_values("_priority", ascending=False)
    .drop_duplicates(subset=["url"], keep="first")
    .drop(columns="_priority")
    .reset_index(drop=True)
)

out = DATA / "urls_combined.csv"
combined.to_csv(out, index=False)

print(f"public       {len(public):>7,}")
print(f"collected_my {len(collected):>7,}")
print(f"combined     {len(combined):>7,}  ({before - len(combined)} duplicate URLs dropped)")
print("\nby source and label")
print(pd.crosstab(combined["source"], combined["label"]).to_string())

share = len(collected) / len(combined)
print(f"\ncollected_my is {share:.3%} of the combined corpus")
if share < 0.01:
    print(
        "\n  NOTE: at well under 1% of the corpus, these rows cannot meaningfully\n"
        "  shift what the model learns. Their value here is as EVALUATION data —\n"
        "  a held-out slice of real Malaysian URLs — rather than as training\n"
        "  signal. Reported metrics should therefore break the collected slice\n"
        "  out separately rather than letting it vanish into the average."
    )

print(f"\nwrote {out}")
sys.exit(0)
