"""ScamSense ML service.

An internal-only Flask application serving two classifiers to the Laravel
backend: a Random Forest over lexical URL features and a DistilBERT message
classifier. It is never exposed to the internet (AGENTS.md §2, rule 1) and it
never touches MySQL — the only contract between the two services is the JSON in
AGENTS.md §4.
"""

from __future__ import annotations

import os
from typing import Any

from flask import Flask

__version__ = "0.1.0"


def create_app(config: dict[str, Any] | None = None) -> Flask:
    """Build and configure the Flask application.

    The app-factory pattern is what makes the service testable: each test gets
    a fresh instance instead of sharing one module-level global. Nothing runs
    at import time except this function's definition, so importing the package
    is cheap and side-effect free.

    Model loading happens once here at startup, never per request — a
    transformer costs roughly two seconds to load, which alone would blow the
    3-second end-to-end budget in objective O2. Phase 4 adds those loads.
    """
    app = Flask(__name__)

    app.config.from_mapping(
        SECRET_KEY=os.environ.get("ML_SERVICE_SECRET"),
        # Flask verifies Laravel's HMAC over the request body and rejects
        # anything older than this many seconds (AGENTS.md §3).
        SIGNATURE_TTL_SECONDS=60,
        JSON_SORT_KEYS=False,
    )

    if config:
        app.config.update(config)

    from app.routes import bp as core_bp

    app.register_blueprint(core_bp)

    return app
