"""Core routes for the ScamSense ML service."""

from __future__ import annotations

from flask import Blueprint, Response, jsonify

from app import __version__
from app.predictors import loaded_model_versions

bp = Blueprint("core", __name__)


@bp.get("/health")
def health() -> tuple[Response, int]:
    """Report which model versions this process has loaded.

    Laravel's health check hits this endpoint (AGENTS.md §4). The response
    reports what is *actually* loaded, so in Phase 0 both models read
    ``null`` rather than a plausible-looking version string. A health check
    that lies about its models is worse than no health check at all.
    """
    versions = loaded_model_versions()

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
