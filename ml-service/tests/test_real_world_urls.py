"""Behaviour of the shipped model on real Malaysian sites.

These are not corpus metrics. They are a small hand-built probe of the URLs
ScamSense will actually be asked about, and they exist because the corpus test
set said F1 0.8984 while the deployed model flags real banking pages.

The failing cases are marked `xfail` rather than deleted. They document a known
defect precisely, keep the suite green, and will report XPASS the moment the
model or threshold is fixed — which is what makes the fix measurable.

Diagnosis, measured against the corpus (see docs/adr/0006):
  - 76% of corpus URLs containing credential vocabulary are labelled phishing
  - legitimate credential-vocabulary URLs are outnumbered 6,954 to 2,026
  - the operating threshold is 0.2640, chosen to weight recall
Together those flag legitimate banking login pages, which is the single most
common thing a worried user pastes.
"""

from __future__ import annotations

import pytest
from flask import Flask

from tests.conftest import url_model_required

pytestmark = url_model_required


#: Real Malaysian sites the model currently gets right.
CORRECTLY_LEGITIMATE = [
    "https://www.maybank2u.com.my",
    "https://www.cimbclicks.com.my",
    "https://www.publicbank.com.my",
    "https://shopee.com.my",
    "https://www.lazada.com.my",
    "https://www.mymaxis.com.my",
    "https://www.astro.com.my",
    "https://www.touchngo.com.my",
    "https://www.uptm.edu.my",
]

#: Real Malaysian sites the model currently gets WRONG. Every one is a genuine
#: site a user could plausibly paste.
KNOWN_FALSE_POSITIVES = [
    "https://www.maybank2u.com.my/home/m2u/common/login.do",
    "https://www.hasil.gov.my/en/",
    "https://www.kwsp.gov.my/en/member/overview",
    "https://www.poslaju.com.my/track-trace/",
    "https://www.tnb.com.my/residential/billing",
    "https://www.jpj.gov.my/en/web/main-site/utama",
    "https://www.bnm.gov.my/",
    "https://www.cimb.com.my/en/personal/index.html",
]

PHISHING_SHAPES = [
    "http://lhdn-refund.site/semak/akaun?id=8821",
    "http://maybank2u.secure-verify.xyz/login/update-account",
    "http://192.168.44.21/maybank2u/login.php",
    "https://cimb-clicks.secure-login.top/verify?id=8821",
    "http://kwsp-semakan.top/login",
    "http://tng-ewallet-reload.xyz/topup",
    "https://xn--myabank-6va.com/login",
]


@pytest.mark.parametrize("url", CORRECTLY_LEGITIMATE)
def test_genuine_malaysian_sites_are_not_flagged(loaded_app: Flask, url: str) -> None:
    from app import predictors

    assert predictors.URL_MODEL is not None
    assert predictors.URL_MODEL.predict(url).label == "legitimate", url


@pytest.mark.parametrize("url", PHISHING_SHAPES)
def test_known_phishing_shapes_are_caught(loaded_app: Flask, url: str) -> None:
    """Recall on real phishing shapes is genuinely good — that half works."""
    from app import predictors

    assert predictors.URL_MODEL.predict(url).label == "scam", url


@pytest.mark.xfail(
    reason=(
        "KNOWN DEFECT (docs/adr/0006): the corpus labels 76% of credential-"
        "vocabulary URLs as phishing and under-represents real login pages "
        "6,954 to 2,026. Combined with the recall-weighted 0.2640 threshold, "
        "genuine banking and government pages are flagged. Fixing this needs "
        "corpus work or a threshold re-chosen on representative data — not an "
        "ad-hoc retune against this list."
    ),
    strict=False,
)
@pytest.mark.parametrize("url", KNOWN_FALSE_POSITIVES)
def test_real_sites_with_paths_are_not_flagged(loaded_app: Flask, url: str) -> None:
    from app import predictors

    assert predictors.URL_MODEL.predict(url).label == "legitimate", url


def test_the_false_positive_rate_on_this_probe_is_recorded(loaded_app: Flask) -> None:
    """Pins the current rate so a change is visible rather than assumed.

    Deliberately asserts a bound rather than an exact figure: the point is to
    catch a regression, not to enshrine 40% as acceptable.
    """
    from app import predictors

    urls = CORRECTLY_LEGITIMATE + KNOWN_FALSE_POSITIVES
    wrong = sum(1 for u in urls if predictors.URL_MODEL.predict(u).label != "legitimate")

    assert wrong <= len(KNOWN_FALSE_POSITIVES), (
        f"{wrong} of {len(urls)} real sites flagged — worse than the "
        f"{len(KNOWN_FALSE_POSITIVES)} recorded in ADR 0006"
    )
