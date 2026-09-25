"""HMAC verification tests (AGENTS.md §3).

These cover the control that authenticates the internal Laravel-to-Flask hop.
The service binds to loopback, but loopback is not a trust boundary on a shared
host, so this is the thing standing between any local process and the
prediction routes.
"""

from __future__ import annotations

import time

import pytest
from flask.testing import FlaskClient

from app import _clean_secret, create_app
from app.security import compute_signature, verify_request
from tests.conftest import TEST_SECRET, signed_headers

BODY = {"url": "https://example.com", "request_id": "abc-123"}


# ── The unit level ─────────────────────────────────────────────────────────


def test_a_correctly_signed_request_verifies() -> None:
    body = b'{"url":"https://example.com"}'
    timestamp = str(time.time())

    result = verify_request(
        TEST_SECRET, compute_signature(TEST_SECRET, timestamp, body), timestamp, body
    )

    assert result.ok


def test_a_tampered_body_fails() -> None:
    """The whole point: an attacker who alters the payload invalidates it."""
    timestamp = str(time.time())
    signature = compute_signature(TEST_SECRET, timestamp, b'{"url":"https://good.example"}')

    result = verify_request(TEST_SECRET, signature, timestamp, b'{"url":"https://evil.example"}')

    assert not result.ok
    assert result.error == "signature_invalid"


def test_a_replayed_timestamp_cannot_be_swapped() -> None:
    """The signature covers timestamp + body together.

    If it covered only the body, a captured request could be replayed forever
    by supplying a fresh timestamp.
    """
    body = b'{"url":"https://example.com"}'
    old = str(time.time() - 300)
    signature = compute_signature(TEST_SECRET, old, body)

    # Attacker keeps the valid signature but supplies a current timestamp.
    result = verify_request(TEST_SECRET, signature, str(time.time()), body)

    assert not result.ok


def test_a_stale_request_is_rejected() -> None:
    body = b"{}"
    timestamp = str(time.time() - 120)

    result = verify_request(
        TEST_SECRET, compute_signature(TEST_SECRET, timestamp, body), timestamp, body
    )

    assert not result.ok


def test_a_request_just_inside_the_window_is_accepted() -> None:
    body = b"{}"
    timestamp = str(time.time() - 55)

    result = verify_request(
        TEST_SECRET, compute_signature(TEST_SECRET, timestamp, body), timestamp, body
    )

    assert result.ok


def test_a_future_timestamp_is_rejected() -> None:
    """A clock far ahead would otherwise widen the replay window indefinitely."""
    body = b"{}"
    timestamp = str(time.time() + 600)

    result = verify_request(
        TEST_SECRET, compute_signature(TEST_SECRET, timestamp, body), timestamp, body
    )

    assert not result.ok


def test_a_missing_secret_refuses_to_serve() -> None:
    """Accepting unsigned requests because the operator forgot to set a secret
    is exactly the misconfiguration this control exists to prevent."""
    result = verify_request(None, "whatever", str(time.time()), b"{}")

    assert not result.ok
    assert result.error == "service_misconfigured"


@pytest.mark.parametrize("timestamp", ["not-a-number", "", "NaN-ish"])
def test_a_malformed_timestamp_is_rejected(timestamp: str) -> None:
    assert not verify_request(TEST_SECRET, "sig", timestamp, b"{}").ok


def test_failure_details_do_not_reveal_which_check_failed() -> None:
    """Distinguishing "wrong signature" from "expired timestamp" tells an
    attacker which half of the check to work on."""
    body = b"{}"
    stale = str(time.time() - 300)

    wrong_signature = verify_request(TEST_SECRET, "0" * 64, str(time.time()), body)
    expired = verify_request(TEST_SECRET, compute_signature(TEST_SECRET, stale, body), stale, body)

    assert wrong_signature.detail == expired.detail


# ── Through the routes ─────────────────────────────────────────────────────


@pytest.mark.parametrize("route", ["/api/v1/predict/url", "/api/v1/predict/message"])
def test_an_unsigned_request_is_rejected(client: FlaskClient, route: str) -> None:
    response = client.post(route, json=BODY)

    assert response.status_code == 401
    assert response.get_json()["error"] == "signature_missing"


@pytest.mark.parametrize("route", ["/api/v1/predict/url", "/api/v1/predict/message"])
def test_a_wrongly_signed_request_is_rejected(client: FlaskClient, route: str) -> None:
    # S106: a deliberately incorrect key, which is the point of the test.
    raw, headers = signed_headers(BODY, secret="the-wrong-secret")  # noqa: S106

    response = client.post(route, data=raw, headers=headers)

    assert response.status_code == 401
    assert response.get_json()["error"] == "signature_invalid"


@pytest.mark.parametrize("route", ["/api/v1/predict/url", "/api/v1/predict/message"])
def test_a_stale_signed_request_is_rejected(client: FlaskClient, route: str) -> None:
    raw, headers = signed_headers(BODY, timestamp=str(time.time() - 120))

    response = client.post(route, data=raw, headers=headers)

    assert response.status_code == 401


def test_a_missing_timestamp_header_is_rejected(client: FlaskClient) -> None:
    raw, headers = signed_headers(BODY)
    del headers["X-ScamSense-Timestamp"]

    assert client.post("/api/v1/predict/url", data=raw, headers=headers).status_code == 401


def test_the_signature_is_checked_before_the_schema(client: FlaskClient) -> None:
    """An unsigned caller must not be able to probe the schema by comparing a
    422 against a 401."""
    response = client.post("/api/v1/predict/url", json={"totally": "wrong"})

    assert response.status_code == 401


def test_health_needs_no_signature(client: FlaskClient) -> None:
    """Laravel's health check is unauthenticated by design; it exposes only
    version strings."""
    assert client.get("/health").status_code == 200


# ── Replay: security review finding 8 ──────────────────────────────────────


def test_a_replayed_request_is_rejected() -> None:
    """The timestamp window bounds replay; the nonce cache closes it.

    On loopback an attacker had to already be on the host. The service now runs
    on a platform's private network, so a 60-second window is worth closing
    rather than documenting as accepted.
    """
    body = b'{"url":"https://example.com","request_id":"abc"}'
    timestamp = str(time.time())
    signature = compute_signature(TEST_SECRET, timestamp, body)

    first = verify_request(TEST_SECRET, signature, timestamp, body, request_id="abc")
    second = verify_request(TEST_SECRET, signature, timestamp, body, request_id="abc")

    assert first.ok
    assert not second.ok


def test_two_genuinely_different_requests_both_verify() -> None:
    """The nonce must not reject ordinary traffic."""
    timestamp = str(time.time())

    for request_id in ("req-1", "req-2", "req-3"):
        body = f'{{"url":"https://example.com","request_id":"{request_id}"}}'.encode()
        signature = compute_signature(TEST_SECRET, timestamp, body)
        assert verify_request(
            TEST_SECRET, signature, timestamp, body, request_id=request_id
        ).ok, request_id


def test_a_replay_is_rejected_through_the_route(client: FlaskClient) -> None:
    raw, headers = signed_headers(BODY)

    first = client.post("/api/v1/predict/url", data=raw, headers=headers)
    second = client.post("/api/v1/predict/url", data=raw, headers=headers)

    # The first is rejected only because no model is loaded in this fixture;
    # what matters is that the second fails on the signature instead.
    assert first.status_code != 401
    assert second.status_code == 401
    assert second.get_json()["error"] == "signature_invalid"


def test_the_nonce_cache_stays_bounded() -> None:
    """An unbounded set keyed on attacker-supplied values is itself a DoS."""
    from app.security import MAX_NONCES, _seen_nonces

    now = time.time()
    for i in range(MAX_NONCES + 50):
        verify_request(
            TEST_SECRET,
            compute_signature(TEST_SECRET, str(now), f"{i}".encode()),
            str(now),
            f"{i}".encode(),
            now=now,
        )

    assert len(_seen_nonces) <= MAX_NONCES


def test_expired_nonces_are_dropped_so_the_cache_does_not_grow_forever() -> None:
    from app.security import _seen_nonces

    old = time.time() - 3600
    verify_request(
        TEST_SECRET,
        compute_signature(TEST_SECRET, str(old), b"old"),
        str(old),
        b"old",
        now=old,
    )

    now = time.time()
    verify_request(
        TEST_SECRET,
        compute_signature(TEST_SECRET, str(now), b"new"),
        str(now),
        b"new",
        now=now,
    )

    assert len(_seen_nonces) == 1


# ── Whitespace on the shared secret ─────────────────────────────────────────


def test_a_padded_secret_is_treated_as_the_clean_one() -> None:
    """start.bat launched the service with `set VAR=%SECRET% && ...`, which in
    batch assigns the value including the space before the `&&`. The process
    then held a secret one character longer than Laravel's, so every signed
    request failed with a 401 while /health kept reporting healthy — being
    unauthenticated, it never exercises the signature. Laravel degraded
    correctly, so the only symptom was that every verdict on the site read "we
    could not check this properly", which looks like a broken model rather than
    a mismatched secret.

    The launcher is fixed; this makes the whole class of quoting bug harmless.
    """
    assert _clean_secret(f"{TEST_SECRET} ") == TEST_SECRET
    assert _clean_secret("  " + TEST_SECRET + chr(13) + chr(10)) == TEST_SECRET


def test_a_whitespace_only_secret_counts_as_absent() -> None:
    """Refusing to serve is the only safe response to a missing secret, so " "
    must not be mistaken for one that was configured."""
    assert _clean_secret("   ") is None
    assert _clean_secret("") is None
    assert _clean_secret(None) is None


def test_a_padded_secret_verifies_what_laravel_signed() -> None:
    """The end-to-end shape of the bug: Laravel signs with the clean value."""
    app = create_app({"LOAD_MODELS": False, "ML_SERVICE_SECRET": f"{TEST_SECRET} "})

    timestamp = str(time.time())
    body = b'{"url":"https://padded.example.test","request_id":"pad-1"}'
    signature = compute_signature(TEST_SECRET, timestamp, body)

    result = verify_request(app.config["ML_SERVICE_SECRET"], signature, timestamp, body)

    assert result.ok
