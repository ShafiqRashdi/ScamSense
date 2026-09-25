"""Split assignment when collected rows are merged into the corpus.

The risky part of merging is not reading the workbook, it is deciding which
partition each new row joins. Get that wrong and the corpus still trains, still
reports a figure, and the figure is quietly inflated — which is exactly how the
synthetic corpus in ADR 0005 produced F1 1.0000.
"""

from __future__ import annotations

from app.features.text_preprocess import template_key
from training.merge_corpus import TARGET_SPLIT, assign_splits, mask

#: Words rather than numbers. `template_key` substitutes [NUM] for every digit
#: run, so "mesej 1" and "mesej 2" collapse to a single template — which is the
#: point of the function and made the first draft of these tests assert the
#: opposite of what they intended.
WORDS = (
    "bungkusan kastam cukai hadiah bank akaun telefon alamat resit tuntutan "
    "pinjaman kerja pelaburan mesyuarat sekolah klinik bil elektrik air talian "
    "insurans pencen simpanan pindahan baki caj denda saman notis makluman "
    "permohonan kelulusan pengesahan pendaftaran pembatalan peringatan"
).split()


def distinct(n: int) -> str:
    """A message whose template is unlike any other produced here."""
    a = WORDS[n % len(WORDS)]
    b = WORDS[(n * 7 + 3) % len(WORDS)]
    c = WORDS[(n * 13 + 5) % len(WORDS)]

    return f"Perkara {a} berkaitan {b} dan juga {c} perlu diambil perhatian."


def row(text: str, label: str = "scam", scam_type: str = "Parcel scam") -> dict[str, str]:
    return {"text": text, "label": label, "scam_type": scam_type}


def test_rows_sharing_a_template_land_in_the_same_partition() -> None:
    """The regression this module was rewritten for.

    A first version dealt rows individually, so two newly collected rows sharing
    a template could land on opposite sides of the split. Templates shared
    between train and test went from 5 to 27. A template group is indivisible.
    """
    rows = [row(f"Parcel anda tertahan di kastam. Bayar RM{n}.00 sekarang.") for n in range(20)]

    assigned = assign_splits(rows, skeleton_split={})

    assert len({assigned[i] for i in range(len(rows))}) == 1


def test_a_template_already_in_the_corpus_inherits_its_partition() -> None:
    """Otherwise a collected row could put an existing test template into train,
    which is the same leak arriving from the other direction."""
    text = "LHDN: Anda layak menerima bayaran balik cukai RM1,280."
    rows = [row(text)]

    assigned = assign_splits(rows, skeleton_split={template_key(text): "test"})

    assert assigned[0] == "test"


def test_distinct_templates_are_spread_across_all_three_partitions() -> None:
    rows = [row(distinct(n)) for n in range(60)]

    assigned = assign_splits(rows, skeleton_split={})

    assert {assigned[i] for i in range(len(rows))} == {"train", "validation", "test"}


def test_the_split_lands_near_its_target_shares() -> None:
    rows = [row(distinct(n)) for n in range(200)]

    assigned = assign_splits(rows, skeleton_split={})
    counts = {name: sum(1 for v in assigned.values() if v == name) for name, _ in TARGET_SPLIT}

    for name, share in TARGET_SPLIT:
        actual = counts[name] / len(rows)
        assert abs(actual - share) < 0.06, f"{name}: {actual:.0%} against a {share:.0%} target"


def test_each_category_reaches_the_test_partition() -> None:
    """Stratified by scam_type as well as label, so a sparse category is not
    absent from test by chance — which would make its recall unmeasurable, and
    sparse categories are the ones whose recall is in question."""
    rows = [
        row(distinct(n + offset * 100), scam_type=category)
        for offset, category in enumerate(
            ("Parcel scam", "Lucky draw / prize", "Macau scam / impersonation")
        )
        for n in range(10)
    ]

    assigned = assign_splits(rows, skeleton_split={})

    for category in ("Parcel scam", "Lucky draw / prize", "Macau scam / impersonation"):
        splits = {assigned[i] for i, r in enumerate(rows) if r["scam_type"] == category}
        assert "test" in splits, category


def test_assignment_is_deterministic() -> None:
    """Re-running the merge must produce the same corpus, or a figure measured
    before it cannot be compared with one measured after."""
    rows = [row(distinct(n)) for n in range(40)]

    assert assign_splits(rows, {}) == assign_splits(rows, {})


def test_phone_numbers_are_masked_with_the_token_the_corpus_uses() -> None:
    masked, changed = mask("Sila hubungi 03-8911 3344 untuk maklumat lanjut.")

    assert changed
    assert "03-8911 3344" not in masked
    assert "[PHONE]" in masked


def test_identification_numbers_are_masked() -> None:
    masked, changed = mask("No. KP saya 020304-14-5678 untuk pengesahan.")

    assert changed
    assert "020304-14-5678" not in masked
    assert "[IC]" in masked


def test_text_without_personal_data_is_left_alone() -> None:
    """Masking must not rewrite the amounts and reference numbers that carry the
    rhetorical shape of a scam."""
    original = "Bil elektrik TNB bulan Julai anda berjumlah RM128.40."
    masked, changed = mask(original)

    assert not changed
    assert masked == original
