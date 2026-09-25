"""Prediction route tests — the contract in AGENTS.md §4.

Split into three groups: the response envelope, input validation, and the
degraded paths. The envelope tests are the ones that break if someone changes
the contract without updating Laravel, which is why they assert exact key sets
rather than just presence.
"""

from __future__ import annotations

import time

import pytest
from flask.testing import FlaskClient

from app import predictors
from app.schemas import MESSAGE_MAX_CHARS, URL_MAX_CHARS
from tests.conftest import signed_headers, url_model_required

PHISH = "http://maybank2u.secure-verify.xyz/login/update-account?ref=8821X9"
BENIGN = "https://www.maybank2u.com.my/home/m2u/common/login.do"


def post(client: FlaskClient, route: str, body: dict[str, object]):
    raw, headers = signed_headers(body)
    return client.post(route, data=raw, headers=headers)


def predict_url(client: FlaskClient, url: str, request_id: str = "req-1"):
    return post(client, "/api/v1/predict/url", {"url": url, "request_id": request_id})


# ── The response envelope ──────────────────────────────────────────────────


@url_model_required
def test_the_response_matches_the_contract_exactly(loaded_client: FlaskClient) -> None:
    """Exact key set, not just presence. An extra or renamed field is a
    breaking change for Laravel's DTO (AGENTS.md §4)."""
    body = predict_url(loaded_client, PHISH).get_json()

    assert set(body) == {
        "request_id",
        "label",
        "confidence",
        "indicators",
        "model_version",
        "inference_ms",
    }


@url_model_required
def test_indicator_entries_match_the_contract(loaded_client: FlaskClient) -> None:
    indicators = predict_url(loaded_client, PHISH).get_json()["indicators"]

    assert indicators, "a blatant phishing URL should produce at least one indicator"
    for indicator in indicators:
        assert set(indicator) == {"code", "weight", "evidence", "source"}
        assert 0.0 <= indicator["weight"] <= 1.0
        assert indicator["source"] in {"model", "pattern", "threat_intel"}


@url_model_required
def test_the_request_id_is_echoed_back(loaded_client: FlaskClient) -> None:
    """Laravel correlates the response to its submission by this."""
    response = predict_url(loaded_client, BENIGN, request_id="corr-xyz-99")

    assert response.get_json()["request_id"] == "corr-xyz-99"


@url_model_required
def test_the_model_version_is_the_shipped_artefact(loaded_client: FlaskClient) -> None:
    """Persisted with every verdict so a Chapter 4 screenshot is traceable."""
    body = predict_url(loaded_client, PHISH).get_json()

    assert body["model_version"] == "rf-url-v2.0.0-pathnorm"


@url_model_required
def test_confidence_is_reported_for_the_label_returned(loaded_client: FlaskClient) -> None:
    """A 'legitimate' verdict at probability 0.02 must read as 0.98 confident,
    not 0.02 — a user would read the raw probability as the opposite."""
    for url in (PHISH, BENIGN):
        body = predict_url(loaded_client, url).get_json()
        assert 0.5 <= body["confidence"] <= 1.0, f"{url} -> {body}"


@url_model_required
def test_a_blatant_phishing_url_is_labelled_scam(loaded_client: FlaskClient) -> None:
    body = predict_url(loaded_client, "http://192.168.44.21/maybank2u/login.php").get_json()

    assert body["label"] == "scam"


@url_model_required
def test_indicators_are_backed_by_real_feature_values(loaded_client: FlaskClient) -> None:
    """Indicators must reflect what actually fired for this URL, not a generic
    list — that is the difference between an explanation and decoration."""
    codes = {
        i["code"]
        for i in predict_url(loaded_client, "http://192.168.44.21/x/login").get_json()["indicators"]
    }

    assert "ip_literal_host" in codes

    benign_codes = {i["code"] for i in predict_url(loaded_client, BENIGN).get_json()["indicators"]}
    assert "ip_literal_host" not in benign_codes


@url_model_required
def test_no_https_is_reported_as_a_pattern_not_a_model_attribution(
    loaded_client: FlaskClient,
) -> None:
    """uses_https was ablated out of training (ADR 0004), so claiming a model
    importance for it would be exactly the dishonesty the source field exists
    to prevent."""
    indicators = predict_url(loaded_client, "http://plain-http-login.xyz/login").get_json()[
        "indicators"
    ]
    no_https = [i for i in indicators if i["code"] == "no_https"]

    assert no_https, "a plain-http login page should flag no_https"
    assert no_https[0]["source"] == "pattern"
    assert no_https[0]["weight"] == 0.0


@url_model_required
def test_indicators_are_capped(loaded_client: FlaskClient) -> None:
    """Twenty rows of evidence is not an explanation for a non-technical user."""
    nasty = (
        "http://maybank2u.cimb.secure-login-verify.xyz:8080/verify/account/x8Fk29PqLzR4TmXw01Bd?a=1"
    )

    assert len(predict_url(loaded_client, nasty).get_json()["indicators"]) <= 5


# ── Latency ────────────────────────────────────────────────────────────────


@url_model_required
def test_inference_is_fast_enough_for_the_three_second_budget(
    loaded_client: FlaskClient,
) -> None:
    """Objective O2 allows 3s end to end, covering Laravel, this hop, threat
    intel and rendering. The model itself must be a small fraction of that.
    """
    started = time.perf_counter()
    response = predict_url(loaded_client, PHISH)
    elapsed_ms = (time.perf_counter() - started) * 1000

    assert response.status_code == 200
    assert elapsed_ms < 500, f"round trip took {elapsed_ms:.0f}ms"
    assert response.get_json()["inference_ms"] < 400


@url_model_required
def test_reported_inference_ms_is_plausible(loaded_client: FlaskClient) -> None:
    body = predict_url(loaded_client, PHISH).get_json()

    assert isinstance(body["inference_ms"], int)
    assert body["inference_ms"] >= 0


# ── Validation: 422, never a guessed prediction ────────────────────────────


@pytest.mark.parametrize(
    ("body", "why"),
    [
        ({}, "empty body"),
        ({"url": "https://example.com"}, "missing request_id"),
        ({"request_id": "r1"}, "missing url"),
        ({"url": "", "request_id": "r1"}, "empty url"),
        ({"url": "https://x.com", "request_id": ""}, "empty request_id"),
        ({"url": "x" * (URL_MAX_CHARS + 1), "request_id": "r1"}, "url over the cap"),
        ({"url": "https://x.com", "request_id": "r1", "extra": 1}, "unexpected field"),
        ({"url": 12345, "request_id": "r1"}, "wrong type"),
    ],
)
def test_malformed_url_requests_get_422(
    client: FlaskClient, body: dict[str, object], why: str
) -> None:
    """A silently wrong prediction is worse than an error: the user acts on it."""
    response = post(client, "/api/v1/predict/url", body)

    assert response.status_code == 422, why
    assert response.get_json()["error"] == "validation_failed"


@pytest.mark.parametrize(
    "url",
    [
        "javascript:alert(1)",
        "data:text/html,<script>alert(1)</script>",
        "file:///etc/passwd",
        "vbscript:msgbox(1)",
        "ftp://files.example.com/x",
    ],
)
def test_non_http_schemes_are_rejected(client: FlaskClient, url: str) -> None:
    """Laravel's UrlSafetyRule rejects these first. Re-checked here because
    this service must not assume its caller validated correctly."""
    response = predict_url(client, url)

    assert response.status_code == 422


@pytest.mark.parametrize(
    ("body", "why"),
    [
        ({}, "empty body"),
        ({"text": "hello"}, "missing request_id"),
        ({"text": "", "request_id": "r1"}, "empty text"),
        ({"text": "x" * (MESSAGE_MAX_CHARS + 1), "request_id": "r1"}, "text over the cap"),
        ({"text": "hi", "request_id": "r1", "extra": True}, "unexpected field"),
    ],
)
def test_malformed_message_requests_get_422(
    client: FlaskClient, body: dict[str, object], why: str
) -> None:
    response = post(client, "/api/v1/predict/message", body)

    assert response.status_code == 422, why


def test_validation_errors_do_not_leak_the_whole_schema(client: FlaskClient) -> None:
    """The full Pydantic error structure names every field and constraint,
    which is a schema map for anyone probing the endpoint."""
    detail = post(client, "/api/v1/predict/url", {}).get_json()["detail"]

    assert len(detail) < 200
    assert "request_id" not in detail or "url" not in detail


# ── Degraded paths ─────────────────────────────────────────────────────────


def test_the_message_route_returns_503_not_a_guess(client: FlaskClient) -> None:
    """`client` has LOAD_MODELS off, standing in for an absent or unloadable
    artefact. Returning 503 rather than a guessed prediction is what lets
    Laravel build a clearly-labelled partial verdict instead of showing a
    confident answer nobody measured."""
    response = post(
        client, "/api/v1/predict/message", {"text": "Tahniah anda menang", "request_id": "r1"}
    )

    assert response.status_code == 503
    body = response.get_json()
    assert body["error"] == "model_unavailable"
    assert body["request_id"] == "r1"


def test_the_message_route_validates_before_reporting_unavailable(
    client: FlaskClient,
) -> None:
    """Otherwise the route would start accepting rubbish the moment the model
    was unloaded, and regress silently when it is restored."""
    assert post(client, "/api/v1/predict/message", {"nonsense": 1}).status_code == 422


def test_the_url_route_returns_503_when_no_model_is_loaded(client: FlaskClient) -> None:
    """`client` has LOAD_MODELS off, standing in for a corrupt or absent
    artefact. Laravel degrades to a partial verdict rather than a 500."""
    response = predict_url(client, BENIGN)

    assert response.status_code == 503
    assert response.get_json()["error"] == "model_unavailable"


def test_health_reports_both_models_when_loaded(loaded_client: FlaskClient) -> None:
    """/health must report what is actually loaded, never what is expected — a
    health check that lies about its models is worse than none."""
    body = loaded_client.get("/health").get_json()

    # Against the configured artefact names rather than frozen strings: a
    # retrain should not break a test whose subject is "health reports what is
    # actually loaded", and hardcoding the version tests the constant instead.
    assert body["models"]["url"] == predictors.URL_MODEL_DIRNAME
    assert body["models"]["message"] == predictors.MESSAGE_MODEL_DIRNAME
    assert body["models_loaded"] is True


def test_the_message_route_predicts_when_the_model_is_loaded(
    loaded_client: FlaskClient,
) -> None:
    """The regression this exists for: the artefact shipped and was measured,
    but nothing loaded it, so every message submission returned 503 and the
    message half of the product was dark while the metric looked healthy."""
    response = post(
        loaded_client,
        "/api/v1/predict/message",
        {
            "text": (
                "TAHNIAH! Anda memenangi RM5,000. Sila klik pautan dan sahkan "
                "kata laluan anda sekarang untuk menuntut hadiah."
            ),
            "request_id": "r1",
        },
    )

    assert response.status_code == 200
    body = response.get_json()
    assert body["label"] in ("scam", "legitimate")
    assert 0.0 <= body["confidence"] <= 1.0
    assert body["model_version"] == predictors.MESSAGE_MODEL_DIRNAME
    assert body["request_id"] == "r1"


def test_message_indicators_never_claim_model_attribution(
    loaded_client: FlaskClient,
) -> None:
    """Message indicators are a curated pattern layer running alongside a
    character-n-gram model whose real coefficients are per-n-gram and useless to
    a reader. Emitting them as source `model` with a nonzero weight would
    present a regex match as interpretability — the precise dishonesty the
    source field exists to prevent."""
    response = post(
        loaded_client,
        "/api/v1/predict/message",
        {
            "text": "Akaun anda akan disekat segera. Sahkan kata laluan dan bayar RM50.",
            "request_id": "r1",
        },
    )

    indicators = response.get_json()["indicators"]
    assert indicators, "expected the pattern layer to fire on this message"

    for indicator in indicators:
        assert indicator["source"] == "pattern"
        assert indicator["weight"] == 0.0
