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
"""

from __future__ import annotations

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
)

#: Lexical observations that are NOT model features. See the module docstring.
URL_PATTERN_RULES: tuple[IndicatorRule, ...] = (IndicatorRule("no_https", "uses_https", "pattern"),)


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
        case "no_https":
            return parsed.scheme or "http"
        case _:
            return None
