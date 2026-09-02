"""ScamSense ML service.

An internal-only Flask application serving classifiers to the Laravel backend:
a Random Forest over lexical URL features, and — once a usable corpus exists —
a DistilBERT message classifier. It is never exposed to the internet
(AGENTS.md §2, rule 1) and never touches MySQL. The only contract between the
two services is the JSON in AGENTS.md §4.
"""

from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Any

from flask import Flask

__version__ = "0.2.0"


def _clean_secret(value: str | None) -> str | None:
    """Trim a shared secret, treating a whitespace-only value as absent.

    Whitespace either side of a secret is always a shell or launcher artefact,
    never a deliberate part of the key — and it is invisible in `.env`, in a
    platform's variable store, and in the process environment. See the note in
    `create_app` for the bug this exists to make harmless.
    """
    if value is None:
        return None

    cleaned = value.strip()

    return cleaned or None


def create_app(config: dict[str, Any] | None = None) -> Flask:
    """Build and configure the Flask application.

    The app-factory pattern is what makes the service testable: each test gets
    a fresh instance instead of sharing one module-level global. Nothing runs
    at import time, so importing the package is cheap and side-effect free.

    Models load once here at startup, never per request — a transformer costs
    roughly two seconds to load, which alone would blow the 3-second budget in
    objective O2.
    """
    app = Flask(__name__)

    app.config.from_mapping(
        ML_SERVICE_SECRET=os.environ.get("ML_SERVICE_SECRET"),
        # Flask verifies Laravel's HMAC over the request body and rejects
        # anything older than this many seconds (AGENTS.md §3).
        SIGNATURE_TTL_SECONDS=60,
        MODELS_DIR=None,
        LOAD_MODELS=True,
        JSON_SORT_KEYS=False,
    )

    if config:
        app.config.update(config)

    # Applied after the config merge so it covers every source, not only the
    # environment. Surrounding whitespace on a shared secret is always a shell
    # or launcher artefact, never part of the key, and it is invisible in every
    # place an operator would think to look.
    #
    # start.bat launched this with `set VAR=%SECRET% && ...`, which in batch
    # assigns the value INCLUDING the space before the `&&`. The service then
    # held a secret one character longer than Laravel's, so every signed request
    # failed the HMAC check with a 401 — while /health, being unauthenticated
    # and therefore never exercising the signature, kept reporting "ok,
    # models_loaded true". Laravel degraded correctly, so the only visible
    # symptom was that every verdict on the site read "We could not check this
    # properly", which looks like a broken model rather than a mismatched
    # secret. It cost three debugging sessions before anyone compared the two
    # values byte for byte.
    #
    # The launcher is fixed; this makes the whole class of quoting bug harmless
    # rather than merely fixed once.
    app.config["ML_SERVICE_SECRET"] = _clean_secret(app.config.get("ML_SERVICE_SECRET"))

    logging.basicConfig(level=logging.INFO)

    from app import predictors
    from app.routes import api as api_bp
    from app.routes import bp as core_bp

    if app.config["LOAD_MODELS"]:
        models_dir = app.config["MODELS_DIR"]
        predictors.load_models(Path(models_dir) if models_dir else None)

    app.register_blueprint(core_bp)
    app.register_blueprint(api_bp)

    return app
