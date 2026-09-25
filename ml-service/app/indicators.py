"""Turning feature values into user-facing indicators.

The `indicators` array is what makes a verdict trustworthy rather than an
oracle, so it cannot be decorative. Every indicator emitted here is backed by
an actual computed feature value for the URL in question — never by a guess
about what probably fired.

## Weight means model importance, not confidence

Each indicator's weight is the Random Forest's learned importance for the
feature that triggered it, read from `metrics.json`. It answers "how much does
this signal matter to the model in general", not "how certain are we about this
URL". Conflating the two would be a subtle lie in the UI.

## Two sources, kept distinct

Most indicators map to a feature the model actually trained on, so their source
is `model`. A few — `no_https` in particular — are lexical observations worth
showing the user that are *not* model features. `uses_https` was removed from
training because the corpus fabricated it for 16% of the negative class (ADR
0004), yet a plain-http login page is still worth flagging to a human.

Those are emitted with source `pattern` and weight 0, because claiming a model
importance for a feature the model never saw would be exactly the dishonesty
the source field exists to prevent.

## Message indicators are a pattern layer, not model interpretability

The message classifier is TF-IDF over character n-grams into Logistic
Regression. Its coefficients are per-n-gram, so the honest rendering of "why"
would be a list of character fragments — useless to a reader and misleading if
dressed up as reasoning.

Message indicators are therefore a curated keyword layer running *alongside*
the model, exactly as the ml-endpoint skill prescribes. They contribute
evidence; the model contributes the score. Every one carries source `pattern`
and weight 0 so that nothing downstream can mistake them for learned
importance. This is a hybrid and is documented as one in ADR 0010 — presenting
a regex match as model interpretability would be a lie the architecture makes
easy and the source field exists to prevent.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from app.features.url_features import ParsedUrl, parse

#: Maximum indicators returned. The UI shows a short list to a non-technical
#: reader; twenty rows of evidence is not an explanation.
MAX_INDICATORS = 5


@dataclass(frozen=True)
class IndicatorRule:
    """Maps a feature crossing a threshold to a user-facing code."""

    code: str
    feature: str
    source: str
    #: Emitted when the feature's value exceeds this.
    threshold: float = 0.5


#: Ordered by how strongly each signals phishing when it fires. `feature` names
#: must exist in url_features.FEATURE_NAMES, which a test asserts — a typo would
#: otherwise silently mean an indicator that can never fire.
URL_RULES: tuple[IndicatorRule, ...] = (
    IndicatorRule("ip_literal_host", "host_is_ip_literal", "model"),
    IndicatorRule("punycode_host", "has_punycode", "model"),
    IndicatorRule("brand_lookalike", "brand_lookalike", "model"),
    IndicatorRule("credential_trick_at_symbol", "has_at_symbol", "model"),
    IndicatorRule("url_shortener", "is_known_shortener", "model"),
    IndicatorRule("suspicious_tld", "is_suspicious_tld", "model"),
    IndicatorRule("excessive_subdomains", "subdomain_count", "model", threshold=2.5),
    IndicatorRule("non_standard_port", "has_port", "model"),
    IndicatorRule("sensitive_keyword_path", "has_sensitive_keyword", "model"),
    IndicatorRule("long_random_path", "longest_token_length", "model", threshold=20.0),
    IndicatorRule("high_entropy_host", "host_entropy", "model", threshold=3.8),
    # Continuous features, thresholded where they genuinely separate the
    # classes rather than where they merely differ. See NOT_SURFACED below for
    # why these two and not the other five.
    #
    # host_length > 29 is the legitimate 95th percentile: 84.7% of URLs above it
    # are phishing, against a 49.7% base rate, firing on 14.2% of traffic.
    # hyphen_count > 1 (two or more) is 85.4% precise, firing on 6.8%.
    IndicatorRule("long_hostname", "host_length", "model", threshold=29.0),
    IndicatorRule("many_hyphens", "hyphen_count", "model", threshold=1.0),
)

#: Features carrying real model importance that are deliberately NOT surfaced.
#:
#: Together these hold a large share of the model's importance mass, so their
#: absence looks like an oversight. It is not. Measured against a 49.7% base
#: rate on a 25,000-URL sample, each fires at close to chance no matter where
#: the threshold is put:
#:
#:   url_length   52.3% precise at the legitimate p90, 41.5% at p99
#:   digit_ratio  62.9% at p90
#:   path_depth   39.5% at p90 — below the base rate
#:   path_length, path_entropy — same shape as path_depth
#:
#: This matches the artefact audit (ADR 0006): url_length has a label AUC of
#: 0.5896 against a source AUC of 0.8978, meaning it mostly identifies which
#: dataset a row came from. It is load-bearing for the model — dropping it cost
#: 8.9 F1 points — and simultaneously near-useless as a reason to give a person.
#:
#: Showing "this address is unusually long" on a coin-flip would manufacture
#: false confidence in a user who cannot audit the claim. A model may use a
#: weak feature in combination; an explanation shown to a person may not.
NOT_SURFACED: tuple[str, ...] = (
    "url_length",
    "digit_ratio",
    "path_depth",
    "path_length",
    "path_entropy",
)

#: Lexical observations that are NOT model features. See the module docstring.
URL_PATTERN_RULES: tuple[IndicatorRule, ...] = (IndicatorRule("no_https", "uses_https", "pattern"),)


@dataclass(frozen=True)
class MessageRule:
    """A curated pattern whose match becomes user-facing evidence."""

    code: str
    pattern: re.Pattern[str]


def _any_of(*alternatives: str) -> re.Pattern[str]:
    return re.compile("|".join(alternatives), re.IGNORECASE | re.UNICODE)


#: Bilingual by necessity. The collected corpus is 417 Malay, 81 code-switched
#: and 30 unclassified, so an English-only pattern set would stay silent on the
#: majority of real submissions — the messages that matter most.
#:
#: Ordered by how strongly each signals a scam when it fires, which is also the
#: order a truncated list keeps.
MESSAGE_RULES: tuple[MessageRule, ...] = (
    MessageRule(
        "credential_request",
        _any_of(
            r"\b(?:kata\s*laluan|katalaluan|nombor\s*pin|no\.?\s*pin|tac|otp)\b",
            r"\bpassword\b",
            r"\b(?:sahkan|pengesahan|mengesahkan)\s+(?:akaun|identiti|maklumat)\b",
            r"\b(?:verify|confirm)\s+(?:your\s+)?(?:account|identity|details)\b",
            r"\b(?:log\s*masuk|login)\b.{0,30}\b(?:segera|sekarang|now|immediately)\b",
            r"\b(?:kemas\s*kini|update)\b.{0,30}\b(?:maklumat|akaun|details|account)\b",
        ),
    ),
    MessageRule(
        "payment_demand",
        _any_of(
            r"\b(?:bayar|pembayaran|bayaran|pindah\s*wang|transfer)\b",
            r"\b(?:cukai|denda|saman|tunggakan|hutang)\b",
            r"\brm\s?\d",
            r"\b(?:duti\s*setem|caj\s*pemprosesan|yuran\s*pemprosesan)\b",
            r"\b(?:processing\s*fee|outstanding|overdue|penalty)\b",
        ),
    ),
    MessageRule(
        "prize_claim",
        _any_of(
            r"\b(?:tahniah|syabas)\b",
            r"\bcongratulations?\b",
            r"\b(?:anda\s+)?(?:telah\s+)?(?:memenangi|menang|terpilih|dipilih)\b",
            r"\b(?:you(?:'ve| have)?\s+)?won\b",
            r"\b(?:hadiah|cabutan\s*bertuah|percuma)\b",
            r"\b(?:prize|lucky\s*draw|reward|free\s+gift)\b",
            r"\b(?:bantuan|geran|subsidi|insentif)\b.{0,20}\b(?:tunai|rm)\b",
        ),
    ),
    MessageRule(
        "urgency_language",
        _any_of(
            r"\b(?:segera|serta[-\s]?merta|sekarang\s*juga|cepat)\b",
            r"\b(?:dalam\s+tempoh|sebelum)\s+\d{0,3}\s*(?:jam|hari|minit)\b",
            r"\b(?:akan\s+)?(?:disekat|digantung|ditutup|dibekukan|terbatal)\b",
            r"\b(?:urgent|immediately|expires?|suspend(?:ed)?|block(?:ed)?|deactivat)\b",
            r"\b(?:last|final)\s+(?:chance|warning|reminder)\b",
            r"\b(?:tindakan\s+segera|amaran\s+terakhir|peringatan\s+akhir)\b",
        ),
    ),
)


def build_url_indicators(
    url: str,
    features: dict[str, float],
    importances: dict[str, float],
) -> list[dict[str, object]]:
    """Produce the indicator list for one URL.

    `features` are that URL's actual computed values; `importances` come from
    the trained model's metrics.json. An indicator is emitted only when the
    feature genuinely fired.
    """
    parsed = parse(url)
    found: list[dict[str, object]] = []

    for rule in URL_RULES:
        value = features.get(rule.feature)
        if value is None or value <= rule.threshold:
            continue

        found.append(
            {
                "code": rule.code,
                # The model's learned importance. Absent from importances means
                # the feature was ablated out, so weight 0 rather than a guess.
                "weight": round(float(importances.get(rule.feature, 0.0)), 4),
                "evidence": _evidence_for(rule.code, parsed, value),
                "source": rule.source,
            }
        )

    # Pattern observations fire on the inverse: no_https when uses_https is 0.
    for rule in URL_PATTERN_RULES:
        if features.get(rule.feature, 1.0) == 0.0 or (
            rule.code == "no_https" and parsed.scheme != "https"
        ):
            found.append(
                {
                    "code": rule.code,
                    "weight": 0.0,
                    "evidence": _evidence_for(rule.code, parsed, 0.0),
                    "source": rule.source,
                }
            )

    # Strongest first, so a truncated list keeps the most informative rows.
    # Ties break on rule order, which is ordered by signal strength.
    order = {rule.code: i for i, rule in enumerate(URL_RULES + URL_PATTERN_RULES)}
    found.sort(key=lambda ind: (-float(ind["weight"]), order.get(str(ind["code"]), 99)))

    return found[:MAX_INDICATORS]


def build_message_indicators(text: str) -> list[dict[str, object]]:
    """Produce the indicator list for one message.

    Every entry is source `pattern` with weight 0. See the module docstring:
    these run alongside the classifier, they are not an explanation of it, and
    the response fields must not imply otherwise.

    Evidence is the matched span from the user's own message, so the reader can
    see the phrase that triggered each row in their own words. It is capped so
    a pathological pattern cannot return the whole message as "evidence".
    """
    found: list[dict[str, object]] = []

    for rule in MESSAGE_RULES:
        match = rule.pattern.search(text)
        if match is None:
            continue

        found.append(
            {
                "code": rule.code,
                "weight": 0.0,
                "evidence": _message_evidence(text, match),
                "source": "pattern",
            }
        )

    return found[:MAX_INDICATORS]


#: Characters of surrounding context shown either side of a pattern match.
_EVIDENCE_CONTEXT = 24


def _message_evidence(text: str, match: re.Match[str]) -> str:
    """The matched phrase plus a little context, trimmed to word boundaries.

    Context matters for a reader: "segera" alone is not recognisable as the
    thing they read, but "akaun anda akan disekat segera" is. Laravel escapes
    this on render and redacts it before storage, so it is display evidence
    only.
    """
    start = max(0, match.start() - _EVIDENCE_CONTEXT)
    end = min(len(text), match.end() + _EVIDENCE_CONTEXT)
    excerpt = text[start:end].strip()

    # Avoid starting or ending mid-word, which reads as a typo rather than a
    # quotation.
    if start > 0 and " " in excerpt:
        excerpt = excerpt.split(" ", 1)[1]
    if end < len(text) and " " in excerpt:
        excerpt = excerpt.rsplit(" ", 1)[0]

    prefix = "…" if start > 0 else ""
    suffix = "…" if end < len(text) else ""

    return f"{prefix}{excerpt}{suffix}"


def _evidence_for(code: str, parsed: ParsedUrl, value: float) -> str | None:
    """A short excerpt showing what triggered the indicator.

    Always drawn from the submitted URL, so the user can see the reason in
    their own input. Laravel escapes this on render and never places it in an
    href — it is evidence, not a link.
    """
    match code:
        case "ip_literal_host" | "high_entropy_host" | "punycode_host":
            return parsed.host or None
        case "brand_lookalike":
            return parsed.registrable_domain or parsed.host or None
        case "suspicious_tld":
            return f".{parsed.tld}" if parsed.tld else None
        case "url_shortener":
            return parsed.host or None
        case "excessive_subdomains":
            return parsed.host or None
        case "non_standard_port":
            return str(parsed.port) if parsed.port else None
        case "sensitive_keyword_path" | "long_random_path":
            return (parsed.path[:120] or None) if parsed.path else None
        case "credential_trick_at_symbol":
            return parsed.host or None
        case "long_hostname" | "many_hyphens":
            return parsed.host or None
        case "no_https":
            return parsed.scheme or "http"
        case _:
            return None
