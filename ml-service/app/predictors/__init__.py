"""Model singletons.

Each predictor loads its artefact exactly once, at application startup, and is
then reused for every request (AGENTS.md §5, and the ml-endpoint skill).

Phase 0 ships the registry only. ``loaded_model_versions`` therefore reports
``None`` for both models, which is the honest answer until Phase 2 trains the
Random Forest and Phase 3 fine-tunes DistilBERT. Nothing here fabricates a
version string to make /health look finished.
"""

from __future__ import annotations

MESSAGE_MODEL: object | None = None
URL_MODEL: object | None = None


def loaded_model_versions() -> dict[str, str | None]:
    """Return the version of each currently loaded model, or None if absent."""
    return {
        "message": getattr(MESSAGE_MODEL, "version", None),
        "url": getattr(URL_MODEL, "version", None),
    }
