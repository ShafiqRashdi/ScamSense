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
