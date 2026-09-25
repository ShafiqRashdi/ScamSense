"""Check collected rows before they reach a corpus file.

    python training/validate_corpus.py                      # the workbook
    python training/validate_corpus.py --data path/to.csv   # a CSV instead

Run this after every collection session. Every check here corresponds to
something that has actually gone wrong in this project or in one of the two
corpora it rejected, which is why each one exists rather than being defensive
in general.

Problems are reported at two levels:

  ERROR    the training run will fail, or the figure it produces will be wrong
  WARNING  worth fixing, but the corpus is usable

Exits non-zero if there is at least one error, so it can gate a merge.
"""

from __future__ import annotations

import argparse
import collections
import csv
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.text_preprocess import template_key  # noqa: E402
from training import corpus_workbook  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "training" / "data"
WORKBOOK = ROOT.parent / "scamsense_corpus_collection.xlsx"

VALID_LABELS = {"scam", "legitimate"}

#: Accepted by `split_from_column` in train_message_model.py. Anything else
#: exits the training run with "Unrecognised split value(s)".
VALID_SPLITS = {"train", "training", "val", "valid", "validation", "dev", "test", "holdout"}

EXPECTED_SOURCE = "collected_my"

#: Malaysian mobile and landline shapes. Mirrors RedactorService::PATTERN_PHONE
#: so the two sides agree on what counts as a phone number.
PHONE = re.compile(
    r"(?<![\d\w])\(?(?:\+?60|0)[\s\-.]?[1-9]\d?\)?[\s\-.]?\d{3,4}[\s\-.]?\d{4}(?!\d)"
)
IC = re.compile(r"\d{6}-\d{2}-\d{4}|(?<!\d)\d{12}(?!\d)")


class Report:
    def __init__(self) -> None:
        self.errors: list[str] = []
        self.warnings: list[str] = []

    def error(self, message: str) -> None:
        self.errors.append(message)

    def warn(self, message: str) -> None:
        self.warnings.append(message)

    def section(self, title: str) -> None:
        print(f"\n{title}")
        print("-" * len(title))

    def line(self, text: str) -> None:
        print(f"  {text}")


def load_existing() -> tuple[list[dict[str, str]], list[dict[str, str]]]:
    messages_path = DATA / "messages_my_with_metadata.csv"
    urls_path = DATA / "legit_urls_my.csv"

    messages = (
        list(csv.DictReader(messages_path.open(encoding="utf-8"))) if messages_path.exists() else []
    )
    urls = list(csv.DictReader(urls_path.open(encoding="utf-8"))) if urls_path.exists() else []

    return messages, urls


def check_messages(rows: list, existing: list[dict[str, str]], report: Report) -> None:
    report.section(f"Messages — {len(rows)} collected rows")

    if not rows:
        report.line("none found")
        return

    # ── the two that stop a training run ─────────────────────────────────
    bad_split = [r for r in rows if r.split_raw.lower() not in VALID_SPLITS]
    if bad_split:
        values = collections.Counter(r.split_raw or "(blank)" for r in bad_split)
        report.error(
            f"{len(bad_split)} rows have a `split` value the training run cannot read. "
            f"It must be one of {sorted(VALID_SPLITS)}."
        )
        report.line(f"ERROR  split unusable on {len(bad_split)} rows:")
        for value, count in values.most_common(6):
            report.line(f"         {count:>4}x  {value!r}")
    else:
        report.line(f"ok     split valid on all {len(rows)} rows")

    bad_source = [r for r in rows if r.source_raw != EXPECTED_SOURCE]
    if bad_source:
        values = collections.Counter(r.source_raw or "(blank)" for r in bad_source)
        report.error(
            f"{len(bad_source)} rows have `source` other than '{EXPECTED_SOURCE}'. "
            "Provenance separation in RESULTS.md and on the dashboard depends on it."
        )
        report.line(f"ERROR  source wrong on {len(bad_source)} rows:")
        for value, count in values.most_common(6):
            report.line(f"         {count:>4}x  {value!r}")
    else:
        report.line(f"ok     source is '{EXPECTED_SOURCE}' on all rows")

    bad_label = [r for r in rows if r.label not in VALID_LABELS]
    if bad_label:
        report.error(f"{len(bad_label)} rows have a label that is not scam or legitimate.")
        report.line(f"ERROR  label invalid on rows: {[r.row_number for r in bad_label][:10]}")
    else:
        report.line("ok     every label is scam or legitimate")

    blank_type = [r for r in rows if not r.scam_type]
    if blank_type:
        report.warn(
            f"{len(blank_type)} rows have no scam_type. That column is what made the "
            "generalisation failure diagnosable; without it a future probe cannot "
            "attribute a miss to category scarcity."
        )
        report.line(f"WARN   scam_type blank on {len(blank_type)} rows")

    # ── duplication, within and against the existing corpus ──────────────
    texts = [r.text.strip().lower() for r in rows]
    within = {t: n for t, n in collections.Counter(texts).items() if n > 1}
    if within:
        report.warn(f"{len(within)} texts appear more than once in the collected rows.")
        report.line(f"WARN   {len(within)} duplicate texts within the new rows")
        for text, count in list(within.items())[:3]:
            report.line(f"         {count}x  {text[:64]}")

    existing_text = {r["text"].strip().lower() for r in existing}
    against = [t for t in texts if t in existing_text]
    if against:
        report.warn(f"{len(against)} collected rows already exist in the corpus verbatim.")
        report.line(f"WARN   {len(against)} exact duplicates of existing rows")
    else:
        report.line("ok     no exact duplicates of the existing corpus")

    # ── skeleton collisions ──────────────────────────────────────────────
    #
    # Not duplicates: the same template with different slot fillers. They matter
    # because a template appearing on both sides of a split is what made the
    # synthetic corpus unusable (ADR 0005), and the merge assigns these to the
    # partition their twin already sits in rather than at random.
    existing_skeletons = {r["text"]: template_key(r["text"]) for r in existing}
    skeleton_to_split = {
        existing_skeletons[r["text"]]: r.get("split", "") for r in existing if r.get("split")
    }
    collisions = [r for r in rows if template_key(r.text) in skeleton_to_split]
    if collisions:
        report.line(
            f"note   {len(collisions)} rows share a template with the existing corpus. "
            "The merge places each with its twin so no template straddles a split."
        )

    skeletons = {template_key(r.text) for r in rows}
    share = len(skeletons) / len(rows)
    report.line(f"note   {len(skeletons)} distinct templates across {len(rows)} rows ({share:.0%})")
    if share < 0.70:
        report.warn(
            f"Only {share:.0%} of collected rows are distinct templates. Below roughly 70% "
            "the corpus starts measuring template recognition rather than language."
        )

    # ── personal data ────────────────────────────────────────────────────
    with_pii = [(r, "phone") for r in rows if PHONE.search(r.text)]
    with_pii += [(r, "IC") for r in rows if IC.search(r.text)]
    if with_pii:
        report.warn(
            f"{len(with_pii)} rows contain an unmasked phone or identification number. "
            "They contribute nothing — normalise() replaces every number with [NUM] before "
            "the vectoriser sees it — so masking costs no accuracy and keeps the committed "
            "file consistent with the 'redacted at source' claim in DATASETS.md."
        )
        report.line(f"WARN   {len(with_pii)} rows carry unmasked personal data")
        for row, kind in with_pii[:5]:
            report.line(f"         row {row.row_number:>4} [{kind}] {row.text[:52]}")

    # ── shape of what was collected ──────────────────────────────────────
    labels = collections.Counter(r.label for r in rows)
    report.line(f"note   {labels.get('scam', 0)} scam / {labels.get('legitimate', 0)} legitimate")


def check_urls(rows: list, existing: list[dict[str, str]], report: Report) -> None:
    report.section(f"URLs — {len(rows)} collected rows")

    if not rows:
        report.line("none found")
        return

    malformed = [r for r in rows if not r.url.lower().startswith(("http://", "https://"))]
    if malformed:
        report.error(f"{len(malformed)} rows are not http or https URLs.")
        report.line(f"ERROR  malformed on rows: {[r.row_number for r in malformed][:10]}")

    bare = [r for r in rows if not urlparse(r.url).path.strip("/")]
    if bare:
        report.warn(
            f"{len(bare)} URLs are bare domains. The model's remaining false positives are "
            "deep paths on genuine sites, so a bare domain exercises none of the shape that "
            "is actually wrong (ADR 0006)."
        )
        report.line(f"WARN   {len(bare)} bare domains, no path")
    else:
        report.line(f"ok     all {len(rows)} URLs carry a path")

    bad_label = [r for r in rows if r.label not in VALID_LABELS]
    if bad_label:
        report.error(f"{len(bad_label)} URL rows have an invalid label.")

    existing_urls = {r["url"].strip().lower() for r in existing}
    dupes = [r for r in rows if r.url.strip().lower() in existing_urls]
    if dupes:
        report.warn(f"{len(dupes)} URLs already exist in legit_urls_my.csv.")
        report.line(f"WARN   {len(dupes)} duplicates of existing URLs")

    within = {
        u: n for u, n in collections.Counter(r.url.strip().lower() for r in rows).items() if n > 1
    }
    if within:
        report.warn(f"{len(within)} URLs appear more than once in the collected rows.")

    sectors = collections.Counter(r.sector for r in rows if r.sector)
    if sectors:
        report.line("note   " + ", ".join(f"{k} {v}" for k, v in sectors.most_common()))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--workbook",
        type=Path,
        default=WORKBOOK,
        help="Collection workbook to validate.",
    )
    args = parser.parse_args()

    if not args.workbook.exists():
        raise SystemExit(f"Workbook not found: {args.workbook}")

    book = corpus_workbook.read(args.workbook)
    existing_messages, existing_urls = load_existing()

    report = Report()
    print("=" * 68)
    print("CORPUS VALIDATION")
    print("=" * 68)
    print(f"  workbook : {args.workbook.name}")
    print(f"  against  : {len(existing_messages)} existing messages, {len(existing_urls)} URLs")

    check_messages(book.messages, existing_messages, report)
    check_urls(book.urls, existing_urls, report)

    print()
    print("=" * 68)
    if report.errors:
        print(f"{len(report.errors)} ERROR(S) — the corpus is not ready to merge\n")
        for message in report.errors:
            print(f"  * {message}")
    else:
        print("No errors. The collected rows can be merged.")

    if report.warnings:
        print(f"\n{len(report.warnings)} warning(s):\n")
        for message in report.warnings:
            print(f"  * {message}")

    print()
    if report.errors:
        print("  Run: python training/merge_corpus.py --dry-run")
        print("  The merge repairs split, source and masking; it will not invent labels.")

    return 1 if report.errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
