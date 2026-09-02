"""Model singletons.

Each predictor loads its artefact exactly once, at application startup, and is
reused for every request (AGENTS.md §5, and the ml-endpoint skill).

`loaded_model_versions` reports what is *actually* loaded. When a model is
absent it reports `None` rather than a plausible-looking version string — a
health check that lies about its models is worse than no health check.
"""

from __future__ import annotations

import logging
from collections.abc import Callable
from pathlib import Path
from typing import Any

from app.predictors.message_classifier import MessageClassifier
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

#: The message artefact ScamSense ships. ADR 0005 rejected the *synthetic*
#: corpus and no artefact was produced from it; ADR 0009 supersedes that with a
#: model trained on 528 collected Malaysian messages. Naming the served artefact
#: here — rather than globbing for the newest directory — means the models
#: directory can hold superseded versions without one of them silently becoming
#: what production serves.
MESSAGE_MODEL_DIRNAME = "tfidf-msg-v2.0.0-collected-my"

MESSAGE_MODEL: MessageClassifier | None = None
URL_MODEL: UrlClassifier | None = None


def load_models(models_dir: Path | None = None) -> None:
    """Load every available artefact into its singleton.

    A missing or corrupt artefact is logged and left as None rather than
    raising. The service must still start and serve /health and whichever route
    does work — that is what lets Laravel degrade to a partial verdict rather
    than seeing a dead service (AGENTS.md §2, rule 4).
    """
    global MESSAGE_MODEL, URL_MODEL

    base = models_dir or DEFAULT_MODELS_DIR

    URL_MODEL = _load(base / URL_MODEL_DIRNAME, UrlClassifier, "URL")
    MESSAGE_MODEL = _load(base / MESSAGE_MODEL_DIRNAME, MessageClassifier, "message")


def _load(
    model_dir: Path, factory: Callable[[Path], Any], label: str
) -> MessageClassifier | UrlClassifier | None:
    """Construct one predictor, or return None with the reason logged.

    Both failure modes are non-fatal by design and both are distinguishable in
    the log: a missing directory is a deployment that did not ship the artefact,
    while an exception is an artefact that shipped but does not load — most
    likely a scikit-learn version skew between training and serving.
    """
    if not model_dir.is_dir():
        logger.warning("%s model not found at %s — its route will return 503", label, model_dir)
        return None

    try:
        model = factory(model_dir)
    except Exception:
        logger.exception("Failed to load %s model from %s", label, model_dir)
        return None

    logger.info("Loaded %s model %s", label, model.version)

    return model


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
