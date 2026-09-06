"""Behaviour of the shipped message model on messages people actually receive.

The counterpart to `test_real_world_urls.py`, and it exists for the same reason:
the corpus test set reports F1 0.9735 while the model misses textbook Malaysian
scams. A metric measured on messages drawn from one collection effort does not
tell you what happens to a scam phrased a way the collector never saw.

These are not corpus metrics. Every message here was written by hand, none
appears in the corpus, and they follow patterns documented by the National Scam
Response Centre and Bank Negara Malaysia.

Two of them are the UAT scenarios from the project brief, named explicitly below
rather than buried in a parametrised list, because "a legitimate bank message is
not flagged" is an acceptance criterion and should read like one in the output.

The failing cases are marked `xfail` rather than deleted. They document a known
limit precisely, keep the suite green, and will report XPASS the moment a larger
corpus fixes them — which is what makes the fix measurable rather than assumed.

Diagnosis, measured in `training/message_probe.py`:
  recall on out-of-corpus scams tracks how many rows the corpus holds for that
  category. Every miss below sits in a category with fewer than 21 training
  rows; every category above 39 rows is caught. Only corpus work closes these.
"""

from __future__ import annotations

import pytest
from flask import Flask

from tests.conftest import message_model_required

pytestmark = message_model_required


#: Ordinary messages the model correctly leaves alone. These are the traffic
#: scams imitate, which is what makes them the hard negatives.
CORRECTLY_LEGITIMATE = [
    "Kod pengesahan anda ialah 483920. Jangan kongsi kod ini dengan sesiapa.",
    "Bungkusan J&T anda telah dihantar dan akan tiba hari ini. Terima kasih.",
    "Peringatan temujanji klinik pada 20 Ogos jam 10 pagi. Sila hadir 15 minit lebih awal.",
    "Bil elektrik TNB bulan Julai anda berjumlah RM128.40. Tarikh akhir pembayaran 28 Ogos.",
    "Terima kasih. Pembayaran anda telah diterima dan resit dihantar ke emel berdaftar.",
    "Jangan lupa mesyuarat esok pukul 9 pagi kat pejabat ya.",
    "Sekolah akan tutup awal esok kerana cuaca buruk. Sila jemput anak pada pukul 11 pagi.",
    "Baki TnG eWallet anda RM12.30. Tambah nilai melalui aplikasi rasmi.",
    "Pesanan anda telah dibungkus dan akan dihantar dalam masa 2 hari bekerja.",
    "Selamat Hari Raya! Maaf zahir dan batin daripada kami sekeluarga.",
    "Cuti tahunan anda telah diluluskan. Sila rujuk sistem HR untuk butiran.",
]

#: Scams the model catches. Every one belongs to a category holding 39 or more
#: training rows — investment, job offer, loan, phishing link.
CAUGHT_SCAMS = [
    "Bank Negara Malaysia: Akaun anda terlibat dalam kes pengubahan wang haram. "
    "Sila hubungi pegawai kami segera di talian ini.",
    "Tawaran pinjaman peribadi tanpa dokumen. Kelulusan segera. Hanya perlu bayar "
    "duti setem RM250 dahulu.",
    "Kerja part time dari rumah, gaji RM200 sehari. Hanya perlu buat like dan "
    "review. Berminat WhatsApp saya.",
    "Maybank2u: Percubaan log masuk dikesan. Jika bukan anda, sahkan kata laluan "
    "anda di pautan berikut dengan segera.",
    "Your account has been suspended due to unusual activity. Verify your identity "
    "within 12 hours to restore access.",
    "Congratulations! Your number was selected in our lucky draw. Claim your "
    "RM5,000 prize before it expires today.",
]

#: Scams the model still misses. Every one is a scam a Malaysian adult could
#: plausibly receive this week, and every one sits in a sparse category:
#: parcel (18 rows), Macau/impersonation (19), lucky draw (20).
KNOWN_MISSED_SCAMS = [
    "Makluman JPJ: Anda mempunyai saman tertunggak RM300. Sila jelaskan dalam "
    "tempoh 24 jam untuk mengelakkan tindakan mahkamah.",
    "Parcel anda tertahan di kastam. Bayar caj pemprosesan RM3.50 untuk melepaskan "
    "bungkusan anda.",
    "LHDN: Anda layak menerima bayaran balik cukai RM1,280. Sahkan maklumat akaun "
    "bank anda untuk proses pemulangan.",
    "TnG eWallet: Akaun anda akan digantung. Sahkan identiti anda sekarang atau "
    "baki anda akan hilang.",
    "Shopee: Pesanan anda gagal dihantar. Kemas kini alamat dan maklumat pembayaran "
    "anda di sini untuk penghantaran semula.",
    "Anda telah memenangi cabutan bertuah Petronas. Sila berikan nombor akaun untuk "
    "pemindahan hadiah RM8,888.",
]

#: A genuine message the model wrongly flags. One is not a rate, but it is the
#: shape worth watching: terse, English, mostly a reference number, which leaves
#: the character n-grams very little ordinary language to go on.
KNOWN_FALSE_POSITIVES = [
    "Your Grab driver is arriving in 2 minutes. Plate number WXY 1234.",
]


def predict(text: str):  # type: ignore[no-untyped-def]
    from app import predictors

    assert predictors.MESSAGE_MODEL is not None, "message model not loaded"

    return predictors.MESSAGE_MODEL.predict(text)


# ── UAT scenarios from the project brief ───────────────────────────────────
#
# Named individually because they are acceptance criteria, not samples.


def test_uat_a_legitimate_bank_message_is_not_flagged(loaded_app: Flask) -> None:
    """UAT: a legitimate bank message returns low risk.

    A one-time passcode is the single most common genuine bank message, and it
    is also the one a scam most wants to elicit. Flagging it would train the
    user to ignore the platform on exactly the message that matters.
    """
    prediction = predict(
        "Kod pengesahan anda ialah 483920. Jangan kongsi kod ini dengan sesiapa "
        "termasuk kakitangan bank."
    )

    assert prediction.label == "legitimate"
    # `confidence` is reported for the label returned, so this is confidence in
    # "legitimate" rather than a scam probability. Laravel maps a legitimate
    # verdict at this confidence to the low band.
    assert prediction.confidence > 0.5


def test_uat_a_legitimate_bank_message_carries_an_explanation(loaded_app: Flask) -> None:
    """UAT: the low-risk verdict is explained rather than asserted.

    For a clean message the pattern layer correctly fires nothing, so the
    explanation is the absence itself. Laravel renders that as "Nothing specific
    stood out" instead of hiding the section — an empty list is a finding, and a
    missing section reads as a list that failed to load.

    What this pins is that the model returns an indicators array at all, so the
    template has something well-formed to render. The user-facing wording is
    asserted on the Laravel side in VerifyPageTest.
    """
    prediction = predict("Bil elektrik TNB bulan Julai anda berjumlah RM128.40.")

    assert prediction.label == "legitimate"
    assert isinstance(prediction.indicators, list)
    assert prediction.model_version == "tfidf-msg-v2.0.0-collected-my"


def test_uat_a_known_scam_message_is_caught_and_explained(loaded_app: Flask) -> None:
    """UAT: a known scam message returns high risk with readable reasons.

    The mirror of the URL scenario. Indicators come from the curated pattern
    layer, so they carry source `pattern` and weight 0 — a regex match must
    never be presented as model attribution (ADR 0010).
    """
    prediction = predict(
        "TAHNIAH! Anda memenangi RM5,000. Sila klik pautan dan sahkan kata "
        "laluan anda sekarang sebelum tamat tempoh."
    )

    assert prediction.label == "scam"
    assert prediction.indicators, "a scam this overt should produce readable reasons"

    for indicator in prediction.indicators:
        assert indicator["source"] == "pattern"
        assert indicator["weight"] == 0.0


# ── The probe, as tests ────────────────────────────────────────────────────


@pytest.mark.parametrize("text", CORRECTLY_LEGITIMATE)
def test_ordinary_messages_are_not_flagged(loaded_app: Flask, text: str) -> None:
    assert predict(text).label == "legitimate", text


@pytest.mark.parametrize("text", CAUGHT_SCAMS)
def test_scams_in_well_represented_categories_are_caught(loaded_app: Flask, text: str) -> None:
    """Every one of these belongs to a category with 39+ training rows."""
    assert predict(text).label == "scam", text


@pytest.mark.xfail(
    reason=(
        "KNOWN LIMIT (docs/RESULTS.md, training/message_probe.py): the model "
        "misses 6 of 12 hand-written out-of-corpus scams. Every miss sits in a "
        "category holding fewer than 21 training rows — parcel 18, Macau "
        "impersonation 19, lucky draw 20 — while every category above 39 rows "
        "is caught. This is corpus scarcity, not a threshold that can be "
        "retuned; collection is targeted at these three categories."
    ),
    strict=False,
)
@pytest.mark.parametrize("text", KNOWN_MISSED_SCAMS)
def test_scams_in_sparse_categories_are_caught(loaded_app: Flask, text: str) -> None:
    assert predict(text).label == "scam", text


@pytest.mark.xfail(
    reason=(
        "KNOWN LIMIT: a terse English message that is mostly a reference number "
        "gives character n-grams very little ordinary language to weigh. The "
        "corpus is 416 Malay to 1 English, so English legitimate traffic is "
        "barely represented."
    ),
    strict=False,
)
@pytest.mark.parametrize("text", KNOWN_FALSE_POSITIVES)
def test_terse_english_messages_are_not_flagged(loaded_app: Flask, text: str) -> None:
    assert predict(text).label == "legitimate", text


def test_the_miss_rate_on_this_probe_is_recorded(loaded_app: Flask) -> None:
    """Pins the current rate so a change is visible rather than assumed.

    Asserts a bound rather than an exact figure: the point is to catch a
    regression, not to enshrine six missed scams as acceptable. Retraining on a
    larger corpus should make this fail in the good direction, at which point
    the bound comes down.
    """
    scams = CAUGHT_SCAMS + KNOWN_MISSED_SCAMS
    missed = sum(1 for text in scams if predict(text).label != "scam")

    assert missed <= len(KNOWN_MISSED_SCAMS), (
        f"{missed} of {len(scams)} scams missed — worse than the "
        f"{len(KNOWN_MISSED_SCAMS)} recorded in docs/RESULTS.md"
    )


def test_no_legitimate_message_is_confidently_flagged(loaded_app: Flask) -> None:
    """A false positive costs a user ten seconds; a missed scam costs money.

    That asymmetry is why the miss rate above is tolerated and this is not: the
    legitimate side must stay reliable, because a platform that cries wolf on
    ordinary traffic gets ignored on the message that matters.
    """
    flagged = [text for text in CORRECTLY_LEGITIMATE if predict(text).label != "legitimate"]

    assert flagged == [], f"genuine messages flagged as scams: {flagged}"
