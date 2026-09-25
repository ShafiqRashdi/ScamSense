"""Tests for message normalisation and template detection."""

from __future__ import annotations

import pytest

from app.features.text_preprocess import (
    NUM_TOKEN,
    URL_TOKEN,
    normalise,
    normalise_lower,
    template_key,
)

# ── Substitution ───────────────────────────────────────────────────────────


def test_urls_are_replaced_with_a_token() -> None:
    """The message model must not memorise specific domains: phishing
    infrastructure is disposable, and the URL model handles links properly."""
    result = normalise("Klik https://maybank2u-verify.xyz/login sekarang")

    assert URL_TOKEN in result
    assert "maybank2u-verify.xyz" not in result


def test_bare_www_hosts_are_replaced() -> None:
    assert URL_TOKEN in normalise("Layari www.lhdn-refund.site untuk tuntutan")


def test_emails_are_replaced() -> None:
    result = normalise("Emel claims@prize-my.com untuk tuntut")

    assert URL_TOKEN in result
    assert "claims@prize-my.com" not in result


def test_numbers_are_replaced_with_a_token() -> None:
    """RM5,000 and RM3,917 are the same rhetorical move; treating them as
    distinct tokens teaches the corpus rather than the pattern."""
    result = normalise("Anda memenangi RM5,000 dalam 24 jam")

    assert NUM_TOKEN in result
    assert "5,000" not in result
    assert "24" not in result


def test_scam_vocabulary_survives_normalisation() -> None:
    """Substitution must not eat the language the model is meant to learn."""
    result = normalise_lower("TAHNIAH! Anda telah memenangi RM5,000. Sila klik segera.")

    for word in ("tahniah", "memenangi", "sila", "klik", "segera"):
        assert word in result


def test_unicode_is_normalised_to_fold_evasion_characters() -> None:
    """Full-width look-alikes are used to dodge naive keyword filters."""
    assert "tahniah" in normalise_lower("ＴＡＨＮＩＡＨ anda menang")


def test_case_is_preserved_by_default() -> None:
    """The project's checkpoint is distilbert-base-multilingual-CASED, so
    lowercasing by default would discard information it was trained to use."""
    assert "TAHNIAH" in normalise("TAHNIAH anda menang")
    assert "tahniah" in normalise_lower("TAHNIAH anda menang")


def test_whitespace_is_collapsed() -> None:
    assert normalise("Sila   hubungi\n\nkami\tsegera") == "Sila hubungi kami segera"


@pytest.mark.parametrize("text", ["", "   ", "\n"])
def test_empty_input_is_handled(text: str) -> None:
    assert normalise(text) == ""


def test_normalise_lower_is_importable_for_pickling() -> None:
    """scikit-learn pickles a vectoriser's preprocessor by reference. A lambda
    trains fine and then fails when the Flask service loads the artefact — the
    worst possible place to find out."""
    import pickle

    # S301: round-tripping a function this test just serialised itself. There is
    # no untrusted input here — the point is to prove the reference resolves.
    assert pickle.loads(pickle.dumps(normalise_lower)) is normalise_lower  # noqa: S301


# ── Template detection ─────────────────────────────────────────────────────


def test_the_same_template_with_different_fillers_shares_a_key() -> None:
    """This is what makes the leakage measurable: two 'different' messages
    generated from one template collapse to the same key."""
    a = "Tahniah! Anda memenangi RM5,000. Klik https://a.xyz untuk tuntut."
    b = "Tahniah! Anda memenangi RM3,917. Klik https://b.top untuk tuntut."

    assert template_key(a) == template_key(b)


def test_genuinely_different_messages_have_different_keys() -> None:
    a = "Tahniah! Anda memenangi RM5,000."
    b = "Notis LHDN: anda mempunyai tunggakan cukai RM1,280."

    assert template_key(a) != template_key(b)


def test_template_key_ignores_punctuation_and_case() -> None:
    assert template_key("TAHNIAH!! Anda menang.") == template_key("tahniah anda menang")


def test_brand_differences_produce_different_templates() -> None:
    """The prize-claim family differs only by brand. Those are near-identical
    but not identical, and the key must reflect that so the grouping is not
    over-aggressive."""
    a = "Tahniah! Anda memenangi hadiah RM100 dari Shopee. Klik https://a.xyz"
    b = "Tahniah! Anda memenangi hadiah RM100 dari Lazada. Klik https://a.xyz"

    assert template_key(a) != template_key(b)
