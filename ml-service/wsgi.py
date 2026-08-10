"""WSGI entrypoint.

Used by the production server (waitress on Windows, gunicorn elsewhere) and by
``flask --app wsgi run`` in development.

Bind to 127.0.0.1 only. This service must never be reachable from the internet:
all public traffic enters through Laravel, which authenticates the internal hop
with an HMAC (AGENTS.md §2, rule 1).
"""

from __future__ import annotations

from dotenv import load_dotenv

load_dotenv()

from app import create_app  # noqa: E402  (must follow load_dotenv)

app = create_app()


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001)
