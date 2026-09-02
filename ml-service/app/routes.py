"""Routes for the ScamSense ML service."""

from __future__ import annotations

from collections.abc import Callable
from functools import wraps
from typing import Any

from flask import Blueprint, Response, current_app, jsonify, request
from pydantic import ValidationError

from app import __version__, predictors
from app.schemas import (
    ErrorResponse,
    PredictionResponse,
    PredictMessageRequest,
    PredictUrlRequest,
)
from app.security import SIGNATURE_HEADER, TIMESTAMP_HEADER, verify_request

bp = Blueprint("core", __name__)
api = Blueprint("api", __name__, url_prefix="/api/v1")


def _error(
    error: str, detail: str, status: int, request_id: str | None = None
) -> tuple[Response, int]:
    body = ErrorResponse(error=error, detail=detail, request_id=request_id)

    return jsonify(body.model_dump()), status


def require_signature(view: Callable[..., Any]) -> Callable[..., Any]:
    """Verify the HMAC before the view does any work.

    Ordering matters: signature first, then schema validation. Validating an
    unsigned body would let an unauthenticated caller probe the schema through
    the difference between a 422 and a 401.
    """

    @wraps(view)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        result = verify_request(
            secret=current_app.config.get("ML_SERVICE_SECRET"),
            signature=request.headers.get(SIGNATURE_HEADER),
            timestamp=request.headers.get(TIMESTAMP_HEADER),
            body=request.get_data(),
            ttl_seconds=int(current_app.config.get("SIGNATURE_TTL_SECONDS", 60)),
        )

        if not result.ok:
            status = 503 if result.error == "service_misconfigured" else 401
            return _error(result.error, result.detail, status)

        return view(*args, **kwargs)

    return wrapper


@bp.get("/health")
def health() -> tuple[Response, int]:
    """Report which model versions this process has loaded.

    Unauthenticated by design: Laravel's health check needs it, and it exposes
    only version strings. It reports what is actually loaded, so a model with
    no artefact reads `null` rather than a plausible-looking version.
    """
    versions = predictors.loaded_model_versions()

    return (
        jsonify(
            {
                "status": "ok",
                "service": "scamsense-ml",
                "version": __version__,
                "models": versions,
                "models_loaded": all(v is not None for v in versions.values()),
            }
        ),
        200,
    )


@api.post("/predict/url")
@require_signature
def predict_url() -> tuple[Response, int]:
    try:
        payload = PredictUrlRequest.model_validate(request.get_json(silent=True) or {})
    except ValidationError as exc:
        return _error("validation_failed", _first_error(exc), 422)

    model = predictors.URL_MODEL
    if model is None:
        # Not an error the user caused. Laravel turns this into a partial
        # verdict rather than a failure (AGENTS.md §2, rule 4).
        return _error(
            "model_unavailable",
            "The URL model is not loaded.",
            503,
            payload.request_id,
        )

    prediction = model.predict(payload.url)

    response = PredictionResponse(
        request_id=payload.request_id,
        label=prediction.label,
        confidence=prediction.confidence,
        indicators=prediction.indicators,
        model_version=prediction.model_version,
        inference_ms=prediction.inference_ms,
    )

    return jsonify(response.model_dump()), 200


@api.post("/predict/message")
@require_signature
def predict_message() -> tuple[Response, int]:
    """Message classification.

    Serves `tfidf-msg-v2.0.0-collected-my`. When the artefact is absent this
    returns 503 with a structured body rather than a guessed prediction, and
    Laravel turns that into a clearly-labelled partial verdict rather than a
    failure (AGENTS.md §2, rule 4).

    The body is validated before the model is consulted, so a malformed request
    gets a 422 whether or not the model is loaded. Reversing that would mean the
    route silently started accepting rubbish the moment an artefact failed to
    load.
    """
    try:
        payload = PredictMessageRequest.model_validate(request.get_json(silent=True) or {})
    except ValidationError as exc:
        return _error("validation_failed", _first_error(exc), 422)

    model = predictors.MESSAGE_MODEL
    if model is None:
        return _error(
            "model_unavailable",
            "The message model is not loaded.",
            503,
            payload.request_id,
        )

    prediction = model.predict(payload.text)

    response = PredictionResponse(
        request_id=payload.request_id,
        label=prediction.label,
        confidence=prediction.confidence,
        indicators=prediction.indicators,
        model_version=prediction.model_version,
        inference_ms=prediction.inference_ms,
    )

    return jsonify(response.model_dump()), 200


def _first_error(exc: ValidationError) -> str:
    """A single readable message from a Pydantic error.

    Deliberately terse. The full error structure names every field and its
    constraints, which is a schema map for anyone probing the endpoint.
    """
    errors = exc.errors()
    if not errors:
        return "Request body failed validation."

    first = errors[0]
    location = ".".join(str(part) for part in first.get("loc", ())) or "body"

    return f"{location}: {first.get('msg', 'invalid')}"
