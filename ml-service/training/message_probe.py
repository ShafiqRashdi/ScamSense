"""Does the message model generalise beyond the corpus it was fitted on?

    python training/message_probe.py

The message classifier reports F1 0.9735 on its own frozen test set, and the
served artefact reproduces that figure exactly. That number is honest, but it
measures performance on messages drawn from the same collection effort as the
training rows — the same 11 legitimate and 7 scam categories, gathered by the
same person over the same weeks.

This probe asks a different and harder question: does it work on a scam it has
never seen phrased this way? Every message below was written by hand for this
file, independently of the corpus, following patterns publicly documented by
the National Scam Response Centre and Bank Negara Malaysia — Macau scam, parcel
and customs scams, LHDN tax demands, e-wallet credential phishing, loan advance
fees, and part-time job recruitment. The legitimate half is drawn from the
ordinary traffic those scams imitate: delivery notifications, OTP messages,
appointment reminders, bills and personal chat.

Every probe message is tagged with the corpus `scam_type` it belongs to, so the
output can be read against how many rows the corpus holds for that type. That
pairing is the point: it turns "the model generalises poorly" into "the model
generalises poorly *on these categories*, which have this many training rows",
which is something a collection plan can act on.

This is the message-side counterpart to `real_world_probe.py` and is reported
the same way: as a probe, not as a test-set metric. It is deliberately small
and deliberately adversarial, so its accuracy is *not* comparable with the
frozen-test-set F1 and must never be quoted as though it were.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.predictors import MESSAGE_MODEL_DIRNAME  # noqa: E402
from app.predictors.message_classifier import MessageClassifier  # noqa: E402

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"
CORPUS = Path(__file__).resolve().parent / "data" / "messages_my_with_metadata.csv"

#: (message, expected_label, corpus_scam_type). The third field names the
#: category in the corpus's own taxonomy so support can be looked up rather than
#: guessed. Written for this file; none appear in the corpus.
PROBE: tuple[tuple[str, str, str], ...] = (
    # ── Scams ──────────────────────────────────────────────────────────────
    (
        "Makluman JPJ: Anda mempunyai saman tertunggak RM300. Sila jelaskan "
        "dalam tempoh 24 jam untuk mengelakkan tindakan mahkamah.",
        "scam",
        "Macau scam / impersonation",
    ),
    (
        "Bank Negara Malaysia: Akaun anda terlibat dalam kes pengubahan wang "
        "haram. Sila hubungi pegawai kami segera di talian ini.",
        "scam",
        "Macau scam / impersonation",
    ),
    (
        "Parcel anda tertahan di kastam. Bayar caj pemprosesan RM3.50 untuk "
        "melepaskan bungkusan anda.",
        "scam",
        "Parcel scam",
    ),
    (
        "LHDN: Anda layak menerima bayaran balik cukai RM1,280. Sahkan "
        "maklumat akaun bank anda untuk proses pemulangan.",
        "scam",
        "Macau scam / impersonation",
    ),
    (
        "TnG eWallet: Akaun anda akan digantung. Sahkan identiti anda sekarang "
        "atau baki anda akan hilang.",
        "scam",
        "Phishing link",
    ),
    (
        "Tawaran pinjaman peribadi tanpa dokumen. Kelulusan segera. Hanya perlu "
        "bayar duti setem RM250 dahulu.",
        "scam",
        "Loan scam",
    ),
    (
        "Kerja part time dari rumah, gaji RM200 sehari. Hanya perlu buat like "
        "dan review. Berminat WhatsApp saya.",
        "scam",
        "Job offer scam",
    ),
    (
        "Maybank2u: Percubaan log masuk dikesan. Jika bukan anda, sahkan kata "
        "laluan anda di pautan berikut dengan segera.",
        "scam",
        "Phishing link",
    ),
    (
        "Your account has been suspended due to unusual activity. Verify your "
        "identity within 12 hours to restore access.",
        "scam",
        "Phishing link",
    ),
    (
        "Congratulations! Your number was selected in our lucky draw. Claim "
        "your RM5,000 prize before it expires today.",
        "scam",
        "Lucky draw / prize",
    ),
    (
        "Shopee: Pesanan anda gagal dihantar. Kemas kini alamat dan maklumat "
        "pembayaran anda di sini untuk penghantaran semula.",
        "scam",
        "Parcel scam",
    ),
    (
        "Anda telah memenangi cabutan bertuah Petronas. Sila berikan nombor "
        "akaun untuk pemindahan hadiah RM8,888.",
        "scam",
        "Lucky draw / prize",
    ),
    # ── Legitimate ─────────────────────────────────────────────────────────
    (
        "Kod pengesahan anda ialah 483920. Jangan kongsi kod ini dengan sesiapa.",
        "legitimate",
        "Bank TAC / OTP",
    ),
    (
        "Bungkusan J&T anda telah dihantar dan akan tiba hari ini. Terima kasih.",
        "legitimate",
        "Delivery notification",
    ),
    (
        "Peringatan temujanji klinik pada 20 Ogos jam 10 pagi. Sila hadir 15 " "minit lebih awal.",
        "legitimate",
        "Appointment reminder",
    ),
    (
        "Bil elektrik TNB bulan Julai anda berjumlah RM128.40. Tarikh akhir " "pembayaran 28 Ogos.",
        "legitimate",
        "Utility bill",
    ),
    (
        "Terima kasih. Pembayaran anda telah diterima dan resit dihantar ke " "emel berdaftar.",
        "legitimate",
        "Bank transaction alert",
    ),
    (
        "Jangan lupa mesyuarat esok pukul 9 pagi kat pejabat ya.",
        "legitimate",
        "Personal message",
    ),
    (
        "Sekolah akan tutup awal esok kerana cuaca buruk. Sila jemput anak " "pada pukul 11 pagi.",
        "legitimate",
        "Government / agency",
    ),
    (
        "Your Grab driver is arriving in 2 minutes. Plate number WXY 1234.",
        "legitimate",
        "Delivery notification",
    ),
    (
        "Baki TnG eWallet anda RM12.30. Tambah nilai melalui aplikasi rasmi.",
        "legitimate",
        "E-wallet",
    ),
    (
        "Pesanan anda telah dibungkus dan akan dihantar dalam masa 2 hari " "bekerja.",
        "legitimate",
        "Delivery notification",
    ),
    (
        "Selamat Hari Raya! Maaf zahir dan batin daripada kami sekeluarga.",
        "legitimate",
        "Personal message",
    ),
    (
        "Cuti tahunan anda telah diluluskan. Sila rujuk sistem HR untuk butiran.",
        "legitimate",
        "Promotion / marketing",
    ),
)


def corpus_support() -> dict[str, int]:
    """How many rows the corpus holds per scam_type.

    Missing corpus file is not fatal — the probe still runs and simply reports
    support as unknown, so this stays usable from a checkout without the data.
    """
    if not CORPUS.exists():
        return {}

    import csv

    counts: dict[str, int] = {}
    with CORPUS.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            key = (row.get("scam_type") or "").strip()
            if key:
                counts[key] = counts.get(key, 0) + 1

    return counts


def main() -> int:
    model_dir = MODELS_DIR / MESSAGE_MODEL_DIRNAME
    if not model_dir.is_dir():
        raise SystemExit(f"Message model not found: {model_dir}")

    model = MessageClassifier(model_dir)
    support = corpus_support()

    print("=" * 74)
    print(f"MESSAGE GENERALISATION PROBE — {model.version}")
    print("=" * 74)
    print("\nHand-written messages, none from the corpus. Not a test-set metric.\n")

    correct = 0
    scam_missed = 0
    legit_flagged = 0
    by_category: dict[str, list[bool]] = {}

    for text, expected, category in PROBE:
        prediction = model.predict(text)
        hit = prediction.label == expected
        correct += hit
        by_category.setdefault(category, []).append(hit)

        if not hit:
            if expected == "scam":
                scam_missed += 1
            else:
                legit_flagged += 1

        mark = "  ok " if hit else "MISS "
        print(f"{mark} [{expected:>10} -> {prediction.label:>10}] {prediction.confidence:.4f}")
        print(f"       {text[:66]}...")

    total = len(PROBE)
    scam_total = sum(1 for _, e, _ in PROBE if e == "scam")

    print("\n" + "-" * 74)
    print(f"  correct              {correct}/{total}  ({correct / total:.1%})")
    print(f"  scams missed         {scam_missed}/{scam_total}")
    print(f"  legitimate flagged   {legit_flagged}/{total - scam_total}")
    print("-" * 74)

    # The whole reason each probe row carries a category: recall is not evenly
    # distributed, and where it fails is a fact about the corpus, not about the
    # algorithm.
    print("\n  Probe accuracy against corpus support, worst first:\n")
    print(f"  {'category':<30} {'corpus rows':>11} {'probe':>8}")
    print("  " + "-" * 51)

    ranked = sorted(
        by_category.items(),
        key=lambda kv: (sum(kv[1]) / len(kv[1]), support.get(kv[0], 0)),
    )
    for category, hits in ranked:
        rows = support.get(category)
        rows_text = str(rows) if rows is not None else "?"
        print(f"  {category:<30} {rows_text:>11} {sum(hits)}/{len(hits):<6}")

    print(
        "\n  A missed scam costs a user money; a flagged legitimate message costs\n"
        "  them ten seconds of doubt. The two are not equally bad, and the\n"
        "  scam-recall column is the one that matters.\n"
    )
    print(
        "  Quote this as a generalisation probe on hand-written messages, never\n"
        "  as the model's F1. The frozen-test-set figure is in docs/RESULTS.md."
    )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
