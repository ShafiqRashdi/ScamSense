"""Probe a trained URL model against real Malaysian sites.

    python training/real_world_probe.py models/rf-url-v2.0.0-pathnorm

These are not corpus metrics. A held-out split of a corpus validates a model
*against that corpus*; it cannot detect that the corpus is drawn from a
different distribution than production. Only real inputs do that, which is how
the defects in ADR 0006 were found in the first place.

Includes the trailing-slash regression check, because that defect was invisible
to every corpus metric.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.url_features import extract_features
from app.predictors.url_classifier import UrlClassifier

MEDIUM_FROM, HIGH_FROM = 0.20, 0.60

GENUINE = [
    "https://www.maybank2u.com.my",
    "https://www.maybank2u.com.my/home/m2u/common/login.do",
    "https://www.cimbclicks.com.my",
    "https://www.cimb.com.my/en/personal/index.html",
    "https://www.publicbank.com.my",
    "https://www.hasil.gov.my/en/",
    "https://www.kwsp.gov.my/en/member/overview",
    "https://www.poslaju.com.my/track-trace/",
    "https://www.tnb.com.my/residential/billing",
    "https://www.jpj.gov.my/en/web/main-site/utama",
    "https://www.bnm.gov.my/",
    "https://shopee.com.my",
    "https://www.lazada.com.my",
    "https://www.mymaxis.com.my",
    "https://www.astro.com.my",
    "https://www.touchngo.com.my",
    "https://www.uptm.edu.my",
    "https://www.google.com",
    "https://en.wikipedia.org/wiki/Malaysia",
]

PHISHING = [
    "http://lhdn-refund.site/semak/akaun?id=8821",
    "http://maybank2u.secure-verify.xyz/login/update-account",
    "http://192.168.44.21/maybank2u/login.php",
    "https://cimb-clicks.secure-login.top/verify?id=8821",
    "http://kwsp-semakan.top/login",
    "http://tng-ewallet-reload.xyz/topup",
    "https://xn--myabank-6va.com/login",
    "https://bit.ly/3xK9mZq",
    "https://maybank2u.com.my.secure-verify.xyz/login",
]


def band(p: float) -> str:
    return "HIGH" if p > HIGH_FROM else ("MEDIUM" if p >= MEDIUM_FROM else "low")


def main() -> int:
    artefact = Path(sys.argv[1])
    clf = UrlClassifier(artefact)

    def prob(url: str) -> float:
        f = extract_features(url)
        return float(clf.model.predict_proba([[f[n] for n in clf.feature_names]])[0][1])

    print(f"artefact  {clf.version}")
    print(f"features  {len(clf.feature_names)}")
    print(f"threshold {clf.threshold:.4f}\n")

    print("=== TRAILING-SLASH REGRESSION (docs/adr/0006) ===")
    worst = 0.0
    for url in GENUINE[:1] + [
        "https://shopee.com.my",
        "https://www.publicbank.com.my",
        "https://en.wikipedia.org",
        "https://www.google.com",
    ]:
        a, b = prob(url), prob(url + "/")
        worst = max(worst, abs(b - a))
        flag = "OK" if abs(b - a) < 1e-9 else "DIFFERS"
        print(f"  {url:<40} {a:.4f} -> {b:.4f}  [{flag}]")
    print(f"\n  largest shift from one slash: {worst:.6f}")
    print(f"  verdict: {'FIXED — a slash changes nothing' if worst < 1e-9 else 'STILL PRESENT'}\n")

    print("=== GENUINE MALAYSIAN AND GLOBAL SITES ===")
    bands = {"low": 0, "MEDIUM": 0, "HIGH": 0}
    for url in GENUINE:
        p = prob(url)
        b = band(p)
        bands[b] += 1
        mark = "XX" if b == "HIGH" else "  "
        print(f"  {mark} {b:<7} {p:.4f}  {url[:62]}")
    print(f"\n  {bands['low']} low, {bands['MEDIUM']} medium, {bands['HIGH']} HIGH")
    print(f"  hard failures (genuine site shown as high risk): {bands['HIGH']}")

    print("\n=== KNOWN PHISHING SHAPES ===")
    caught = 0
    for url in PHISHING:
        p = prob(url)
        b = band(p)
        if b == "HIGH":
            caught += 1
        mark = "  " if b == "HIGH" else "XX"
        print(f"  {mark} {b:<7} {p:.4f}  {url[:62]}")
    print(f"\n  {caught} of {len(PHISHING)} in the high band")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
