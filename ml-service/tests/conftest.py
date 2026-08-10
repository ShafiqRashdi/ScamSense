"""Shared pytest fixtures."""

from __future__ import annotations

from collections.abc import Iterator

import pytest
from flask import Flask
from flask.testing import FlaskClient

from app import create_app


@pytest.fixture
def app() -> Iterator[Flask]:
    application = create_app({"TESTING": True, "ML_SERVICE_SECRET": "test-secret"})
    yield application


@pytest.fixture
def client(app: Flask) -> FlaskClient:
    return app.test_client()
