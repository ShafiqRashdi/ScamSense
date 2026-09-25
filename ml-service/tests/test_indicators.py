"""Indicator generation tests.

The indicator list is what makes a verdict trustworthy rather than an oracle,
so these check that every indicator is backed by a real computed feature value
and that nothing claims model attribution it has not earned.
"""

from __future__ import annotations

from app.features.url_features import FEATURE_NAMES, extract_features
from app.indicators import MAX_INDICATORS, URL_PATTERN_RULES, URL_RULES, build_url_indicators
from app.schemas import IndicatorCode

# A plausible set of importances, standing in for metrics.json.
IMPORTANCES = {name: 0.1 for name in FEATURE_NAMES}


def indicators(url: str, importances: dict[str, float] | None = None):
    return build_url_indicators(url, extract_features(url), importances or IMPORTANCES)


def codes(url: str) -> set[str]:
    return {i["code"] for i in indicators(url)}


# ── Wiring integrity ───────────────────────────────────────────────────────


def test_every_rule_references_a_real_feature() -> None:
    """A typo in a rule's feature name would mean an indicator that can never
    fire — silently, because a missing key is just skipped."""
    for rule in URL_RULES + URL_PATTERN_RULES:
        assert rule.feature in FEATURE_NAMES, f"{rule.code} -> unknown feature {rule.feature}"


def test_every_rule_code_is_in_the_shared_contract_enum() -> None:
    """Flask emits codes; Laravel maps them to Bahasa Malaysia and English.
    A code missing from the enum leaves a user staring at a raw identifier."""
    allowed = set(IndicatorCode.__args__)

    for rule in URL_RULES + URL_PATTERN_RULES:
        assert rule.code in allowed, f"{rule.code} is not in the shared IndicatorCode enum"


def test_rule_codes_are_unique() -> None:
    all_rules = URL_RULES + URL_PATTERN_RULES
    assert len({rule.code for rule in all_rules}) == len(all_rules)


# ── Indicators reflect the actual URL ──────────────────────────────────────


def test_an_ip_host_produces_the_ip_indicator() -> None:
    assert "ip_literal_host" in codes("http://192.168.44.21/maybank2u/login.php")


def test_a_clean_brand_url_produces_no_alarming_indicators() -> None:
    """If these fire on the genuine bank the whole feature is worse than
    useless."""
    found = codes("https://www.maybank2u.com.my/home/m2u/common/login.do")

    assert "ip_literal_host" not in found
    assert "brand_lookalike" not in found
    assert "suspicious_tld" not in found
    assert "no_https" not in found


def test_a_lookalike_domain_is_flagged() -> None:
    found = codes("http://lhdn-refund.site/claim")

    assert "brand_lookalike" in found
    assert "suspicious_tld" in found


def test_punycode_is_flagged() -> None:
    assert "punycode_host" in codes("https://xn--myabank-6va.com/login")


def test_a_shortener_is_flagged() -> None:
    assert "url_shortener" in codes("https://bit.ly/3xK9mZq")


def test_the_at_symbol_trick_is_flagged() -> None:
    assert "credential_trick_at_symbol" in codes("http://maybank2u.com.my@evil.example/x")


def test_evidence_comes_from_the_submitted_url() -> None:
    """The user must be able to see the reason in their own input."""
    found = indicators("http://192.168.44.21/verify/account")
    ip = next(i for i in found if i["code"] == "ip_literal_host")

    assert ip["evidence"] == "192.168.44.21"


# ── Honesty about attribution ──────────────────────────────────────────────


def test_no_https_is_a_pattern_with_zero_weight() -> None:
    """uses_https was ablated out of training (ADR 0004). Claiming a model
    importance for a feature the model never saw is the dishonesty the source
    field exists to prevent."""
    found = indicators("http://plain-login.xyz/login")
    no_https = next(i for i in found if i["code"] == "no_https")

    assert no_https["source"] == "pattern"
    assert no_https["weight"] == 0.0


def test_an_ablated_feature_gets_zero_weight_not_a_guess() -> None:
    """Importances come from metrics.json. A feature absent from it was
    ablated, so the weight is 0 rather than an invented number."""
    found = build_url_indicators(
        "http://192.168.44.21/x",
        extract_features("http://192.168.44.21/x"),
        importances={},
    )

    assert all(i["weight"] == 0.0 for i in found)


def test_weights_reflect_the_supplied_importances() -> None:
    found = build_url_indicators(
        "http://192.168.44.21/x",
        extract_features("http://192.168.44.21/x"),
        importances={"host_is_ip_literal": 0.42},
    )
    ip = next(i for i in found if i["code"] == "ip_literal_host")

    assert ip["weight"] == 0.42


# ── Presentation ───────────────────────────────────────────────────────────


def test_the_list_is_capped() -> None:
    nasty = "http://maybank2u.cimb.secure-login.xyz:8080/verify/account/x8Fk29PqLzR4TmXw01Bd?a=1"

    assert len(indicators(nasty)) <= MAX_INDICATORS


def test_the_strongest_indicators_come_first() -> None:
    """A truncated list must keep the most informative rows."""
    found = build_url_indicators(
        "http://192.168.44.21/verify/account",
        extract_features("http://192.168.44.21/verify/account"),
        importances={"host_is_ip_literal": 0.9, "has_sensitive_keyword": 0.1},
    )

    weights = [i["weight"] for i in found]
    assert weights == sorted(weights, reverse=True)


def test_generation_never_raises_on_hostile_input() -> None:
    for url in ("", "   ", "http://", "https://.", "x" * 2000):
        build_url_indicators(url, extract_features(url), IMPORTANCES)
