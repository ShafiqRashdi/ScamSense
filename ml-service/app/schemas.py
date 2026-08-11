"""Request and response schemas for the prediction API.

These types *are* the contract in AGENTS.md §4. Changing any of them is a
breaking change requiring both sides, both test suites and docs/api-contract.md
to be updated in the same commit.

Every route validates its body here and returns 422 on failure. A malformed
request never reaches a model: a silently wrong prediction is worse than an
error, because the user acts on it.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

#: Length caps from AGENTS.md §3. Enforced by Laravel's FormRequest as well;
#: duplicated here because this service must not trust its caller, even an
#: internal one — a compromised backend is exactly the case HMAC exists for.
MESSAGE_MAX_CHARS = 5000
URL_MAX_CHARS = 2048

#: The closed indicator enum, shared with Laravel (AGENTS.md §4). Flask emits
#: codes only; Laravel maps them to Bahasa Malaysia and English prose. Adding a
#: code here without adding it to lang/ms and lang/en leaves a user staring at
#: a raw identifier.
IndicatorCode = Literal[
    # URL indicators
    "ip_literal_host",
    "punycode_host",
    "excessive_subdomains",
    "suspicious_tld",
    "url_shortener",
    "brand_lookalike",
    "long_random_path",
    "no_https",
    "credential_trick_at_symbol",
    "sensitive_keyword_path",
    "non_standard_port",
    "high_entropy_host",
    # Message indicators (Phase 3, not yet emitted)
    "urgency_language",
    "prize_claim",
    "credential_request",
    "payment_demand",
]

#: Where an indicator came from. Mirrors App\Enums\IndicatorSource on the
#: Laravel side. `model` means a real feature attribution; `pattern` means a
#: curated rule. Keeping them distinct is what stops pattern matches being
#: presented as model interpretability.
IndicatorSource = Literal["model", "pattern", "threat_intel"]


class PredictMessageRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    text: str = Field(min_length=1, max_length=MESSAGE_MAX_CHARS)
    request_id: str = Field(min_length=1, max_length=64)


class PredictUrlRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    url: str = Field(min_length=1, max_length=URL_MAX_CHARS)
    request_id: str = Field(min_length=1, max_length=64)

    @field_validator("url")
    @classmethod
    def scheme_must_be_http(cls, value: str) -> str:
        """Reject anything that is not http or https.

        Laravel's UrlSafetyRule rejects these first, so a `javascript:` payload
        should never arrive. It is re-checked here because this service must not
        assume its caller validated correctly — and because the check is one
        line, while the failure it prevents is an XSS or SSRF primitive being
        handed to a model and echoed back into a UI.
        """
        candidate = value.strip()
        lowered = candidate.lower()

        if "://" in lowered:
            scheme = lowered.split("://", 1)[0]
            if scheme not in ("http", "https"):
                raise ValueError(f"Unsupported URL scheme: {scheme}")
        elif ":" in lowered.split("/", 1)[0]:
            # Schemes without "//" — javascript:, data:, mailto:, vbscript:.
            prefix = lowered.split(":", 1)[0]
            if prefix and not prefix.replace(".", "").replace("-", "").isdigit():
                raise ValueError(f"Unsupported URL scheme: {prefix}")

        return candidate


class Indicator(BaseModel):
    """One human-readable reason contributing to a verdict."""

    model_config = ConfigDict(extra="forbid")

    code: IndicatorCode
    weight: float = Field(ge=0.0, le=1.0)
    evidence: str | None = Field(default=None, max_length=255)
    source: IndicatorSource


class PredictionResponse(BaseModel):
    """The success envelope from both prediction routes (AGENTS.md §4)."""

    model_config = ConfigDict(extra="forbid")

    request_id: str
    label: Literal["scam", "legitimate"]
    confidence: float = Field(ge=0.0, le=1.0)
    indicators: list[Indicator]
    model_version: str
    inference_ms: int = Field(ge=0)


class ErrorResponse(BaseModel):
    """The failure envelope.

    Used for 422 validation failures, 401 signature rejections and 503 when a
    model is not loaded. A structured error means Laravel can distinguish "you
    sent something invalid" from "I cannot answer right now" — and only the
    second should become a partial verdict rather than a user-facing error.
    """

    model_config = ConfigDict(extra="forbid")

    error: str
    detail: str
    request_id: str | None = None
