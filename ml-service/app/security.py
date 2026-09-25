"""HMAC authentication for the internal Laravel to Flask hop.

AGENTS.md §3: Laravel signs each request body with a shared secret; Flask
rejects anything unsigned or older than 60 seconds.

## What this defends against, and what it does not

The ML service is never exposed to the internet (AGENTS.md §2, rule 1): it
binds loopback locally and the platform's private network in deployment. This
is defence in depth rather than the only control — but a private network is not
a trust boundary either. Any other service on it, any compromised container,
and any SSRF elsewhere in the stack could otherwise reach the prediction routes
directly.

The timestamp window bounds replay, and a nonce cache closes what it leaves
open. Security review finding 8: on loopback an attacker must already be on the
host, but the service now runs on a platform's private network, so a replay
window worth 60 seconds is worth closing rather than documenting.

Each request carries a `request_id`, which is already part of the contract, so
the nonce is free — a `request_id` seen twice inside the window is rejected.
The cache is bounded and self-expiring, because an unbounded set keyed on
attacker-supplied values is itself a denial-of-service vector.

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

#: Refuse to grow the nonce cache past this many entries. At the project's rate
#: limits a minute's traffic is far below it, so hitting the cap means something
#: abnormal is happening — and dropping the oldest entries degrades to the
#: timestamp window rather than exhausting memory.
MAX_NONCES = 10_000

#: request_id -> the timestamp it was first seen at. Module-level because the
#: service is single-process; a multi-worker deployment would need shared state,
#: which is noted in docs/deployment.md.
_seen_nonces: dict[str, float] = {}


def _remember_nonce(nonce: str, now: float, ttl_seconds: int) -> bool:
    """Record a nonce, returning False if it has already been used.

    Expired entries are dropped on the way through, so the cache stays bounded
    by the request rate inside one window rather than growing forever.
    """
    cutoff = now - ttl_seconds
    for seen, first in list(_seen_nonces.items()):
        if first < cutoff:
            del _seen_nonces[seen]

    if nonce in _seen_nonces:
        return False

    if len(_seen_nonces) >= MAX_NONCES:
        # Drop the oldest rather than refuse service. Falling back to the
        # timestamp window is a weaker guarantee, not a broken one.
        oldest = min(_seen_nonces, key=lambda k: _seen_nonces[k])
        del _seen_nonces[oldest]

    _seen_nonces[nonce] = now

    return True


def reset_nonces() -> None:
    """Clear the nonce cache. For tests only."""
    _seen_nonces.clear()


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
    request_id: str | None = None,
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

    # Replay: the signature is valid, so this is a genuine request — but it may
    # be the same genuine request arriving twice. The nonce is recorded only
    # after the signature verifies, so an attacker cannot burn nonces by
    # sending unsigned garbage.
    nonce = signature if request_id is None else f"{request_id}:{signature}"
    if not _remember_nonce(nonce, time.time() if now is None else now, ttl_seconds):
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    return VerificationResult(True)
