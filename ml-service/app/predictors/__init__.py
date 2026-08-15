"""Model singletons.

Each predictor loads its artefact exactly once, at application startup, and is
reused for every request (AGENTS.md §5, and the ml-endpoint skill).

`loaded_model_versions` reports what is *actually* loaded. When a model is
absent it reports `None` rather than a plausible-looking version string — a
health check that lies about its models is worse than no health check, and the
message model genuinely has no artefact (ADR 0005).
"""

from __future__ import annotations

import logging
from pathlib import Path

from app.predictors.url_classifier import UrlClassifier

logger = logging.getLogger(__name__)

#: Default artefact directory. Overridable through app config so tests can
#: point at a fixture without touching the real models directory.
DEFAULT_MODELS_DIR = Path(__file__).resolve().parent.parent.parent / "models"

#: The artefact ScamSense ships. See docs/RESULTS.md.
#:
#: This MUST be kept in step with app/features/url_features.py. The v2 artefact
#: was trained with the trailing-slash path normalisation; serving the older
#: v1.2.0 artefact through the newer extractor would feed it features from a
#: distribution it was never fitted on — the training/serving skew that sharing
#: the extraction module exists to prevent.
URL_MODEL_DIRNAME = "rf-url-v2.0.0-pathnorm"

MESSAGE_MODEL: object | None = None
URL_MODEL: UrlClassifier | None = None


def load_models(models_dir: Path | None = None) -> None:
    """Load every available artefact into its singleton.

    A missing artefact is logged and left as None rather than raising. The
    service must still start and serve /health and the URL route when the
    message model is absent — which is the current state, and which is also
    what lets Laravel degrade to a partial verdict rather than seeing a dead
    service (AGENTS.md §2, rule 4).
    """
    global URL_MODEL

    base = models_dir or DEFAULT_MODELS_DIR
    url_dir = base / URL_MODEL_DIRNAME

    if url_dir.is_dir():
        try:
            URL_MODEL = UrlClassifier(url_dir)
            logger.info("Loaded URL model %s", URL_MODEL.version)
        except Exception:
            # A corrupt or mismatched artefact must not take the process down;
            # the URL route will report 503 and Laravel degrades gracefully.
            logger.exception("Failed to load URL model from %s", url_dir)
            URL_MODEL = None
    else:
        logger.warning("URL model not found at %s — /predict/url will return 503", url_dir)
        URL_MODEL = None

    # The message model is deliberately absent. ADR 0005: the available corpus
    # is 56 templates and cannot support a valid evaluation, so no artefact was
    # produced rather than one that would look finished.
    logger.info("Message model not loaded: no artefact exists (see docs/adr/0005)")


def reset_models() -> None:
    """Clear the singletons. For tests only."""
    global URL_MODEL, MESSAGE_MODEL

    URL_MODEL = None
    MESSAGE_MODEL = None


def loaded_model_versions() -> dict[str, str | None]:
    """The version of each currently loaded model, or None if absent."""
    return {
        "message": getattr(MESSAGE_MODEL, "version", None),
        "url": getattr(URL_MODEL, "version", None),
    }
