"""Tests for the /health endpoint (AGENTS.md §4)."""

from __future__ import annotations

from flask.testing import FlaskClient


def test_health_returns_200(client: FlaskClient) -> None:
    assert client.get("/health").status_code == 200


def test_health_reports_the_service_identity(client: FlaskClient) -> None:
    body = client.get("/health").get_json()

    assert body["status"] == "ok"
    assert body["service"] == "scamsense-ml"
    assert isinstance(body["version"], str)


def test_health_reports_both_model_slots(client: FlaskClient) -> None:
    body = client.get("/health").get_json()

    assert set(body["models"]) == {"message", "url"}


def test_health_does_not_claim_models_that_are_not_loaded(client: FlaskClient) -> None:
    """Phase 0 has trained no models, and /health must say so.

    This guards the rule in AGENTS.md §7 against passing a stub off as
    finished: if someone hardcodes a version string to make the endpoint look
    complete, this test fails.
    """
    body = client.get("/health").get_json()

    assert body["models"] == {"message": None, "url": None}
    assert body["models_loaded"] is False


def test_unknown_route_returns_404(client: FlaskClient) -> None:
    assert client.get("/not-a-route").status_code == 404
