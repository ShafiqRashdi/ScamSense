"""Merge collected rows into the corpus files.

    python training/merge_corpus.py --dry-run     # report, write nothing
    python training/merge_corpus.py               # write the files

Run `validate_corpus.py` first. This repairs what can be repaired mechanically
and refuses anything requiring judgement — it will not invent a label, and it
will not decide that two near-identical messages are the same message.

## What it repairs, and why each repair is safe

**`split` and `source`.** In the workbook as collected both hold provenance —
"my phone", "mother received" — rather than the partition and corpus tag the
training script expects. That information is worth keeping and is not thrown
away: it moves to `channel` and `received_from`, two new columns that no
training code reads. `source` becomes `collected_my` and `split` is assigned
below.

**Unmasked phone and identification numbers.** Replaced with the tokens the
corpus already uses. This costs nothing: `normalise()` substitutes `[NUM]` for
every number before the vectoriser sees it, so a masked and an unmasked number
are the same token to the model. What it buys is a committed file that matches
the "redacted at source" claim in DATASETS.md.

**Duplicates.** Exact repeats are dropped, keeping the first.

## How splits are assigned

The existing 528 rows keep the split they already have. That is the whole point:
the 106-row test partition stays held out, so a figure measured after this merge
is directly comparable with the 0.9735 measured before it.

New rows are assigned 65/15/20 — **except** that a row whose template already
appears in the corpus is placed in the same partition as its twin. Without that
rule a template would straddle the split, which is precisely the defect that
made the synthetic corpus unusable (ADR 0005) and which the leak-free subset
metric exists to report.

Within each (label, scam_type) group the assignment is deterministic on a seed,
so re-running produces the same corpus.
"""

from __future__ import annotations

import argparse
import collections
import csv
import random
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.text_preprocess import template_key  # noqa: E402
from training import corpus_workbook  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "training" / "data"
WORKBOOK = ROOT.parent / "scamsense_corpus_collection.xlsx"

SEED = 42
TARGET_SPLIT = [("train", 0.65), ("validation", 0.15), ("test", 0.20)]

PHONE = re.compile(
    r"(?<![\d\w])\(?(?:\+?60|0)[\s\-.]?[1-9]\d?\)?[\s\-.]?\d{3,4}[\s\-.]?\d{4}(?!\d)"
)
IC = re.compile(r"\d{6}-\d{2}-\d{4}|(?<!\d)\d{12}(?!\d)")

#: Already in use in messages_my.csv — [PHONE] 30 times, [NAME] 6, [ACCOUNT] 3.
MASK_PHONE = "[PHONE]"
MASK_IC = "[IC]"

METADATA_COLUMNS = [
    "text",
    "label",
    "scam_type",
    "language",
    "split",
    "source",
    # New. Provenance the collector recorded, kept rather than discarded. No
    # training code reads these; they exist so the corpus can answer "where did
    # this come from" months later, which is a question a panel may well ask.
    "channel",
    "received_from",
]


def mask(text: str) -> tuple[str, bool]:
    masked = PHONE.sub(MASK_PHONE, text)
    masked = IC.sub(MASK_IC, masked)

    return masked, masked != text


def normalise_split(value: str) -> str | None:
    aliases = {
        "train": "train",
        "training": "train",
        "val": "validation",
        "valid": "validation",
        "validation": "validation",
        "dev": "validation",
        "test": "test",
        "holdout": "test",
    }

    return aliases.get(value.strip().lower())


def assign_splits(
    rows: list[dict[str, str]],
    skeleton_split: dict[str, str],
) -> dict[int, str]:
    """Partition for each new row, keyed by its index in `rows`.

    **Assignment is by template, never by row.** A first version dealt rows
    individually and inherited a partition only from the existing corpus, which
    left two newly collected rows sharing a template free to land on opposite
    sides of the split — taking templates shared between train and test from 5
    to 27. That is the defect ADR 0005 rejected an entire corpus over, so the
    unit here is the template group and a group is indivisible.

    Groups whose template already exists inherit that template's partition.
    The rest are dealt inside each (label, scam_type) so a sparse category is
    not accidentally absent from the test set, and greedily to whichever
    partition is furthest below its target share — template groups vary in size,
    so slicing a shuffled list at fixed offsets would drift badly on categories
    with few, large groups.
    """
    # Seeded for reproducibility, not secrecy: re-running the merge must
    # produce the same corpus or figures measured either side of it cannot
    # be compared.
    rng = random.Random(SEED)  # noqa: S311
    assigned: dict[int, str] = {}

    groups: dict[str, list[int]] = collections.defaultdict(list)
    for index, row in enumerate(rows):
        groups[template_key(row["text"])].append(index)

    free: dict[tuple[str, str], list[list[int]]] = collections.defaultdict(list)

    for skeleton, indexes in groups.items():
        inherited = skeleton_split.get(skeleton)

        if inherited is not None:
            for index in indexes:
                assigned[index] = inherited
            continue

        first = rows[indexes[0]]
        free[(first["label"], first["scam_type"])].append(indexes)

    for stratum in sorted(free):
        group_list = free[stratum]
        rng.shuffle(group_list)

        placed = dict.fromkeys((name for name, _ in TARGET_SPLIT), 0)

        # Largest first: a big group placed late would overshoot whatever
        # partition it landed in and there would be nothing left to correct it.
        for indexes in sorted(group_list, key=len, reverse=True):
            total = sum(placed.values()) + len(indexes)
            deficit = {name: (share * total) - placed[name] for name, share in TARGET_SPLIT}
            target = max(deficit, key=lambda name: deficit[name])

            for index in indexes:
                assigned[index] = target
            placed[target] += len(indexes)

    return assigned


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--workbook", type=Path, default=WORKBOOK)
    parser.add_argument("--dry-run", action="store_true", help="Report without writing.")
    args = parser.parse_args()

    book = corpus_workbook.read(args.workbook)

    metadata_path = DATA / "messages_my_with_metadata.csv"
    plain_path = DATA / "messages_my.csv"
    urls_path = DATA / "legit_urls_my.csv"

    existing = list(csv.DictReader(metadata_path.open(encoding="utf-8")))
    existing_urls = list(csv.DictReader(urls_path.open(encoding="utf-8")))

    print("=" * 68)
    print("CORPUS MERGE" + ("  (dry run)" if args.dry_run else ""))
    print("=" * 68)
    print(f"  existing : {len(existing)} messages, {len(existing_urls)} URLs")
    print(f"  collected: {len(book.messages)} messages, {len(book.urls)} URLs")

    # ── messages ─────────────────────────────────────────────────────────
    skeleton_split = {
        template_key(row["text"]): row["split"] for row in existing if row.get("split")
    }
    existing_text = {row["text"].strip().lower() for row in existing}

    prepared: list[dict[str, str]] = []
    seen: set[str] = set()
    dropped_dupe = 0
    dropped_existing = 0
    masked_count = 0

    for row in book.messages:
        text, was_masked = mask(row.text)
        masked_count += was_masked

        key = text.strip().lower()
        if key in seen:
            dropped_dupe += 1
            continue
        if key in existing_text:
            dropped_existing += 1
            continue
        seen.add(key)

        prepared.append(
            {
                "text": text,
                "label": row.label,
                "scam_type": row.scam_type,
                "language": row.language or "ms",
                "split": "",
                "source": "collected_my",
                # The collector put the channel in `split` and the person or
                # date in `source`; both move here intact.
                "channel": row.split_raw,
                "received_from": row.source_raw,
            }
        )

    assigned = assign_splits(prepared, skeleton_split)
    inherited = sum(1 for i, r in enumerate(prepared) if template_key(r["text"]) in skeleton_split)
    for index, row in enumerate(prepared):
        row["split"] = assigned[index]

    print("\nMessages")
    print("--------")
    print(f"  masked phone/IC numbers   {masked_count}")
    print(f"  dropped, duplicate        {dropped_dupe}")
    print(f"  dropped, already present  {dropped_existing}")
    print(f"  kept                      {len(prepared)}")
    print(f"  split inherited by template {inherited}")

    merged = []
    for row in existing:
        merged.append(
            {
                **{column: row.get(column, "") for column in METADATA_COLUMNS},
                "split": row.get("split", ""),
                "source": row.get("source", "collected_my"),
            }
        )
    merged.extend(prepared)

    counts = collections.Counter(row["split"] for row in merged)
    total = sum(counts.values())
    print("\n  merged split distribution:")
    for name in ("train", "validation", "test"):
        n = counts.get(name, 0)
        print(f"    {name:<12}{n:>5}  ({n / total:.0%})")

    labels = collections.Counter(row["label"] for row in merged)
    print(
        f"\n  merged corpus: {total} rows — "
        f"{labels['scam']} scam / {labels['legitimate']} legitimate "
        f"({labels['scam'] / total:.0%} scam)"
    )

    skeletons = {template_key(row["text"]) for row in merged}
    print(f"  distinct templates: {len(skeletons)} ({len(skeletons) / total:.0%})")

    train_skeletons = {template_key(r["text"]) for r in merged if r["split"] == "train"}
    test_skeletons = {template_key(r["text"]) for r in merged if r["split"] == "test"}
    shared = train_skeletons & test_skeletons
    print(f"  templates in both train and test: {len(shared)}")

    # ── urls ─────────────────────────────────────────────────────────────
    existing_url_set = {row["url"].strip().lower() for row in existing_urls}
    new_urls, url_dupes = [], 0
    for row in book.urls:
        key = row.url.strip().lower()
        if key in existing_url_set:
            url_dupes += 1
            continue
        existing_url_set.add(key)
        new_urls.append({"url": row.url, "label": row.label})

    print("\nURLs")
    print("----")
    print(f"  dropped, already present  {url_dupes}")
    print(f"  kept                      {len(new_urls)}")
    print(f"  merged total              {len(existing_urls) + len(new_urls)}")

    if args.dry_run:
        print("\nDry run — nothing was written.")
        return 0

    with metadata_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=METADATA_COLUMNS)
        writer.writeheader()
        writer.writerows(merged)

    with plain_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["text", "label"])
        writer.writeheader()
        writer.writerows({"text": r["text"], "label": r["label"]} for r in merged)

    with urls_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["url", "label"])
        writer.writeheader()
        writer.writerows(existing_urls + new_urls)

    print(f"\nWrote {metadata_path.name}, {plain_path.name}, {urls_path.name}")
    print("\nNext: python training/train_message_model.py \\")
    print("        --data training/data/messages_my_with_metadata.csv \\")
    print("        --version 3.0.0-collected-my --split-strategy given")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
