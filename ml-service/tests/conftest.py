"""Shared pytest fixtures."""

from __future__ import annotations

import json
import time
from collections.abc import Iterator
from pathlib import Path

import pytest
from flask import Flask
from flask.testing import FlaskClient

from app import create_app, predictors
from app.security import (
    SIGNATURE_HEADER,
    TIMESTAMP_HEADER,
    compute_signature,
    reset_nonces,
)

TEST_SECRET = "test-secret-not-a-real-key"  # noqa: S105

#: The real artefact directory. Tests that need a loaded model use it, because
#: a fixture model would not catch a feature-count mismatch between
#: feature_list.json and the trained artefact — which is the exact failure the
#: UrlClassifier guard exists for.
REAL_MODELS_DIR = Path(__file__).resolve().parent.parent / "models"
REAL_URL_MODEL = REAL_MODELS_DIR / predictors.URL_MODEL_DIRNAME

REAL_MESSAGE_MODEL = REAL_MODELS_DIR / predictors.MESSAGE_MODEL_DIRNAME

url_model_required = pytest.mark.skipif(
    not (REAL_URL_MODEL / "model.joblib").exists(),
    reason=(
        "Trained URL artefact absent. Model weights are gitignored, so a clean "
        "checkout must run training/train_url_model.py first."
    ),
)

message_model_required = pytest.mark.skipif(
    not (REAL_MESSAGE_MODEL / "model.joblib").exists(),
    reason=(
        "Trained message artefact absent. Model weights are gitignored, so a "
        "clean checkout must run training/train_message_model.py first."
    ),
)


@pytest.fixture(autouse=True)
def _clear_nonces() -> Iterator[None]:
    """The nonce cache is module-level, so it must not leak between tests."""
    reset_nonces()
    yield
    reset_nonces()


@pytest.fixture
def app() -> Iterator[Flask]:
    """An app with no models loaded — the fast default."""
    predictors.reset_models()
    application = create_app(
        {"TESTING": True, "ML_SERVICE_SECRET": TEST_SECRET, "LOAD_MODELS": False}
    )
    yield application
    predictors.reset_models()


@pytest.fixture
def client(app: Flask) -> FlaskClient:
    return app.test_client()


@pytest.fixture
def loaded_app() -> Iterator[Flask]:
    """An app with the real trained URL model loaded."""
    predictors.reset_models()
    application = create_app(
        {
            "TESTING": True,
            "ML_SERVICE_SECRET": TEST_SECRET,
            "LOAD_MODELS": True,
            "MODELS_DIR": str(REAL_MODELS_DIR),
        }
    )
    yield application
    predictors.reset_models()


@pytest.fixture
def loaded_client(loaded_app: Flask) -> FlaskClient:
    return loaded_app.test_client()


def signed_headers(
    body: dict[str, object],
    secret: str = TEST_SECRET,
    timestamp: str | None = None,
) -> tuple[bytes, dict[str, str]]:
    """Sign a body the way Laravel's MlServiceClient will.

    Returns the exact bytes signed alongside the headers — re-serialising the
    dict would risk a different key order and a signature that cannot verify,
    which is a real trap when the client and server serialise separately.
    """
    raw = json.dumps(body).encode("utf-8")
    stamp = timestamp if timestamp is not None else str(time.time())

    return raw, {
        "Content-Type": "application/json",
        SIGNATURE_HEADER: compute_signature(secret, stamp, raw),
        TIMESTAMP_HEADER: stamp,
    }
