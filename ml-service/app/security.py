"""HMAC authentication for the internal Laravel to Flask hop.

AGENTS.md §3: Laravel signs each request body with a shared secret; Flask
rejects anything unsigned or older than 60 seconds.

## What this defends against, and what it does not

The ML service binds to loopback and is never exposed to the internet
(AGENTS.md §2, rule 1), so this is defence in depth rather than the only
control. It matters because loopback is not a trust boundary on a shared host:
any local process, any other container on the same network, and any SSRF
elsewhere in the stack could otherwise reach the prediction routes directly.

The timestamp window bounds replay. It does not eliminate it — an attacker who
captures a signed request can resend it within 60 seconds. Full replay
prevention needs a nonce cache, which is deliberately out of scope: the
requests carry no side effects, so a replay produces a duplicate prediction and
nothing more.

## Why the signature covers the timestamp

Signing the body alone would let an attacker replay a captured body forever by
supplying a fresh timestamp. The signature is therefore over
`timestamp + "." + body`, binding the two together so neither can be swapped.
"""

from __future__ import annotations

import hashlib
import hmac
import time
from dataclasses import dataclass

SIGNATURE_HEADER = "X-ScamSense-Signature"
TIMESTAMP_HEADER = "X-ScamSense-Timestamp"

DEFAULT_TTL_SECONDS = 60


@dataclass(frozen=True)
class VerificationResult:
    ok: bool
    error: str = ""
    detail: str = ""


def compute_signature(secret: str, timestamp: str, body: bytes) -> str:
    """Produce the hex signature for a request.

    Laravel computes the identical value. The payload is
    `{timestamp}.{body}` — see the module docstring for why the timestamp is
    inside the signed material rather than beside it.
    """
    payload = timestamp.encode("utf-8") + b"." + body

    return hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).hexdigest()


def verify_request(
    secret: str | None,
    signature: str | None,
    timestamp: str | None,
    body: bytes,
    ttl_seconds: int = DEFAULT_TTL_SECONDS,
    now: float | None = None,
) -> VerificationResult:
    """Check a request's signature and freshness.

    Returns a result rather than raising, so the route decides the status code
    and the caller never sees a stack trace.

    Failure reasons are deliberately coarse in the `detail` returned to the
    client — "signature verification failed" rather than "signature mismatch"
    versus "timestamp expired". Distinguishing them tells an attacker which
    half of the check to work on.
    """
    if not secret:
        # Refusing to serve is the only safe response: accepting unsigned
        # requests because the operator forgot to set a secret is precisely
        # the misconfiguration this exists to prevent.
        return VerificationResult(
            False, "service_misconfigured", "ML_SERVICE_SECRET is not configured."
        )

    if not signature or not timestamp:
        return VerificationResult(
            False, "signature_missing", "Request signature or timestamp header is absent."
        )

    try:
        sent_at = float(timestamp)
    except (TypeError, ValueError):
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    current = time.time() if now is None else now

    # Rejects both stale requests and ones timestamped in the future; a clock
    # far ahead would otherwise widen the replay window indefinitely.
    if abs(current - sent_at) > ttl_seconds:
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    expected = compute_signature(secret, timestamp, body)

    # compare_digest, never ==. A plain comparison returns early on the first
    # differing byte, leaking the correct prefix through response timing.
    if not hmac.compare_digest(expected, signature):
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    return VerificationResult(True)
