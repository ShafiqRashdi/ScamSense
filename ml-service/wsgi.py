"""WSGI entrypoint.

Used by the production server (waitress on Windows, gunicorn elsewhere) and by
``flask --app wsgi run`` in development.

## Binding, and why it is configurable rather than hardcoded

AGENTS.md §2 rule 1: this service must never be reachable from the internet. It
binds to **localhost or a private network only**, and all public traffic enters
through Laravel, which authenticates the internal hop with an HMAC.

The default is `127.0.0.1`, which is correct for local development and is the
safe value to fail back to.

On Railway the two services talk over the platform's private IPv6 network, and
a process is only reachable there if it binds `::`. That is still a private
network and still satisfies rule 1 — but **only for as long as no public domain
is generated for this service**. Generating one would put the prediction
endpoint on the internet and break the rule, whatever this file says.

That distinction is why the value is configuration rather than a constant: the
deployment sets it deliberately, and `docs/deployment.md` states the public
domain must never be created. Hugging Face Spaces was rejected for exactly this
reason — it requires a public hostname, and needing to edit this binding to fit
a platform is the architecture objecting.
"""

from __future__ import annotations

import os

from dotenv import load_dotenv

load_dotenv()

from app import create_app  # noqa: E402  (must follow load_dotenv)

app = create_app()


if __name__ == "__main__":
    # 127.0.0.1 unless the deployment explicitly says otherwise.
    host = os.environ.get("FLASK_RUN_HOST", "127.0.0.1")
    port = int(os.environ.get("PORT", os.environ.get("FLASK_RUN_PORT", "5001")))

    app.run(host=host, port=port)
