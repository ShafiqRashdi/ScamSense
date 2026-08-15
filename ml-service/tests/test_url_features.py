"""Unit tests for lexical URL features.

Every feature gets a malicious and a benign example, per the ml-endpoint skill.
The malicious examples are modelled on campaigns that actually circulate in
Malaysia — bank, courier and government impersonation — because a feature that
only separates textbook examples is not worth shipping.
"""

from __future__ import annotations

import ast
from pathlib import Path

import pytest

from app.features import url_features as uf

# Two reference URLs used across the simple cases.
PHISH = "http://maybank2u.secure-verify.xyz/login/update-account?ref=8821X9"
BENIGN = "https://www.maybank2u.com.my/home/m2u/common/login.do"


def features(url: str) -> dict[str, float]:
    return uf.extract_features(url)


# ── The rule that matters most ─────────────────────────────────────────────


def test_module_imports_no_network_library() -> None:
    """ScamSense must never fetch a submitted URL (AGENTS.md §2, rule 3).

    Enforced by inspecting the module's own imports rather than trusting
    discipline: adding `requests` or `socket` here fails the build. This is the
    SSRF control a security-minded panel will probe first, so it is worth
    making mechanical.

    `urllib.parse` is allowed — it is pure string manipulation.
    """
    forbidden = {
        "requests",
        "httpx",
        "socket",
        "urllib.request",
        "urllib3",
        "http.client",
        "aiohttp",
        "dns",
        "whois",
        "ssl",
        "ftplib",
        "telnetlib",
        "subprocess",
    }

    source = Path(uf.__file__).read_text(encoding="utf-8")
    imported: set[str] = set()

    for node in ast.walk(ast.parse(source)):
        if isinstance(node, ast.Import):
            imported.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imported.add(node.module)

    assert not (imported & forbidden), (
        f"url_features.py imports a networking library: {imported & forbidden}. "
        "Lexical analysis only — never fetch a submitted URL."
    )


# ── Parsing ────────────────────────────────────────────────────────────────


def test_parse_defaults_a_missing_scheme_to_http() -> None:
    parsed = uf.parse("maybank2u.com.my/login")

    assert parsed.scheme == "http"
    assert parsed.host == "maybank2u.com.my"
    assert parsed.path == "/login"


def test_parse_lowercases_the_host_but_not_the_path() -> None:
    parsed = uf.parse("https://Example.COM/Path/To")

    assert parsed.host == "example.com"
    assert parsed.path == "/Path/To"


def test_parse_survives_a_malformed_port() -> None:
    # urlsplit raises on an out-of-range port; the submission must not be lost.
    parsed = uf.parse("http://evil.example:99999999/login")

    assert parsed.port is None
    assert parsed.host == "evil.example"


def test_a_bare_root_path_is_normalised_away() -> None:
    """`https://x.com` and `https://x.com/` are the same resource.

    Before this normalisation a single trailing slash moved shopee.com.my from
    0.039 to 0.881 — low risk to high risk on a character carrying no
    information (docs/adr/0006). Browsers add that slash when a user copies from
    the address bar, so it affected ordinary use rather than an edge case.
    """
    assert uf.parse("https://example.com/").path == ""
    assert uf.parse("https://example.com").path == ""

    # A real path is untouched.
    assert uf.parse("https://example.com/login").path == "/login"
    assert uf.parse("https://example.com/a/").path == "/a/"


def test_a_trailing_slash_changes_no_feature() -> None:
    """The regression guard. If this fails, the defect in ADR 0006 is back."""
    for url in (
        "https://www.maybank2u.com.my",
        "https://shopee.com.my",
        "https://www.publicbank.com.my",
        "https://en.wikipedia.org",
        "https://www.google.com",
    ):
        assert features(url) == features(url + "/"), url


def test_malaysian_two_part_suffix_is_not_counted_as_a_subdomain() -> None:
    """Without this, every `.com.my` site would score one subdomain too many
    and the model would be biased against Malaysian domains specifically."""
    assert uf.subdomain_count(uf.parse("https://maybank2u.com.my")) == 0
    assert uf.subdomain_count(uf.parse("https://www.maybank2u.com.my")) == 1
    assert uf.subdomain_count(uf.parse("https://www.hasil.gov.my/en/")) == 1


# ── Length and shape ───────────────────────────────────────────────────────


def test_url_length_separates_padded_phishing_from_a_short_brand_url() -> None:
    assert uf.url_length(uf.parse(PHISH)) > uf.url_length(uf.parse("https://cimb.com.my"))


def test_path_depth() -> None:
    assert uf.path_depth(uf.parse("https://x.example/a/b/c/d")) == 4
    assert uf.path_depth(uf.parse("https://cimb.com.my")) == 0


def test_query_param_count() -> None:
    assert uf.query_param_count(uf.parse("http://x.xyz/p?a=1&b=2&c=3")) == 3
    assert uf.query_param_count(uf.parse("https://poslaju.com.my/track-trace/")) == 0


def test_subdomain_count_flags_stacked_labels() -> None:
    stacked = uf.parse("http://maybank.com.secure-login.xyz/verify")
    assert uf.subdomain_count(stacked) >= 2
    assert uf.subdomain_count(uf.parse("https://www.poslaju.com.my")) == 1


def test_hyphen_count() -> None:
    assert uf.hyphen_count(uf.parse("http://cimb-clicks-secure-login.top")) == 3
    assert uf.hyphen_count(uf.parse("https://www.cimb.com.my")) == 0


def test_longest_token_length_flags_generated_paths() -> None:
    generated = uf.parse("http://x.xyz/a8Fk29PqLzR4TmXw01Bd")
    assert uf.longest_token_length(generated) >= 20
    assert uf.longest_token_length(uf.parse("https://cimb.com.my/personal")) < 20


# ── Character distribution ─────────────────────────────────────────────────


def test_digit_ratio_is_higher_for_a_numeric_host() -> None:
    assert uf.digit_ratio(uf.parse("http://192.168.44.21/maybank2u/login.php")) > 0.2
    assert uf.digit_ratio(uf.parse("https://www.hasil.gov.my/en/")) < 0.1


def test_host_entropy_is_higher_for_a_random_host() -> None:
    random_host = uf.host_entropy(uf.parse("http://x7f3kq9zpw2v.xyz"))
    word_host = uf.host_entropy(uf.parse("https://poslaju.com.my"))

    assert random_host > word_host


def test_entropy_of_empty_string_is_zero() -> None:
    assert uf._shannon_entropy("") == 0.0


def test_path_entropy() -> None:
    assert uf.path_entropy(uf.parse("http://x.xyz/aB9kZ2qL7wR")) > 0
    assert uf.path_entropy(uf.parse("https://x.example")) == 0.0


# ── Binary structural signals ──────────────────────────────────────────────


def test_uses_https() -> None:
    assert uf.uses_https(uf.parse("https://www.maybank2u.com.my")) == 1
    assert uf.uses_https(uf.parse("http://maybank2u-verify.xyz")) == 0


def test_host_is_ip_literal() -> None:
    assert uf.host_is_ip_literal(uf.parse("http://192.168.44.21/maybank2u/")) == 1
    assert uf.host_is_ip_literal(uf.parse("https://www.maybank2u.com.my")) == 0


def test_an_out_of_range_octet_is_not_an_ip_literal() -> None:
    assert uf.host_is_ip_literal(uf.parse("http://999.1.1.1/x")) == 0


def test_ipv6_literal_is_detected() -> None:
    assert uf.host_is_ip_literal(uf.parse("http://[2001:db8::1]/login")) == 1


def test_has_punycode_detects_a_homograph_host() -> None:
    assert uf.has_punycode(uf.parse("https://xn--myabank-6va.com/login")) == 1
    assert uf.has_punycode(uf.parse("https://www.maybank2u.com.my")) == 0


def test_has_at_symbol_detects_the_credential_trick() -> None:
    # Everything before @ is credentials: this resolves to evil.example.
    assert uf.has_at_symbol(uf.parse("http://maybank2u.com.my@evil.example/x")) == 1
    assert uf.has_at_symbol(uf.parse("https://www.maybank2u.com.my")) == 0


def test_an_at_symbol_in_the_query_is_not_the_credential_trick() -> None:
    # A false positive here would flag every "mailto"-ish query string.
    assert uf.has_at_symbol(uf.parse("https://real.com.my/contact?email=a@b.com")) == 0


def test_has_double_slash_in_path() -> None:
    assert uf.has_double_slash_in_path(uf.parse("http://x.xyz/redirect//evil.example")) == 1
    assert uf.has_double_slash_in_path(uf.parse("https://poslaju.com.my/track/")) == 0


def test_has_port_ignores_the_standard_ports() -> None:
    assert uf.has_port(uf.parse("http://evil.example:8080/login")) == 1
    assert uf.has_port(uf.parse("https://www.maybank2u.com.my:443/")) == 0
    assert uf.has_port(uf.parse("https://www.maybank2u.com.my/")) == 0


# ── Reputation-style lexical signals ───────────────────────────────────────


def test_is_known_shortener() -> None:
    assert uf.is_known_shortener(uf.parse("https://bit.ly/3xK9mZq")) == 1
    assert uf.is_known_shortener(uf.parse("https://www.poslaju.com.my/track")) == 0


def test_shortener_detection_ignores_a_www_prefix() -> None:
    assert uf.is_known_shortener(uf.parse("https://www.tinyurl.com/abc")) == 1


def test_is_suspicious_tld() -> None:
    assert uf.is_suspicious_tld(uf.parse("http://cimb-clicks.secure-login.top/x")) == 1
    assert uf.is_suspicious_tld(uf.parse("https://www.cimb.com.my")) == 0


def test_an_ip_literal_has_no_tld() -> None:
    assert uf.parse("http://192.168.1.1/x").tld == ""


# ── Malaysian brand impersonation ──────────────────────────────────────────


def test_brand_token_in_subdomain_flags_a_lookalike() -> None:
    assert uf.brand_token_in_subdomain(uf.parse("http://maybank2u.secure-login.xyz/")) == 1


def test_the_real_brand_domain_is_not_flagged_as_a_lookalike() -> None:
    """The whole feature is worthless if it fires on the genuine site."""
    assert uf.brand_token_in_subdomain(uf.parse("https://maybank2u.com.my/login")) == 0
    assert uf.brand_token_in_subdomain(uf.parse("https://www.cimb.com.my")) == 0
    assert uf.brand_token_in_subdomain(uf.parse("https://www.hasil.gov.my/en/")) == 0


def test_brand_token_in_the_path_of_an_unrelated_domain_is_flagged() -> None:
    assert uf.brand_lookalike(uf.parse("http://secure-site.xyz/maybank2u/login")) == 1


def test_brand_lookalike_flags_a_brand_used_as_the_registrable_domain() -> None:
    """The commonest impersonation shape, and the one an earlier version of
    this feature missed entirely: the brand token IS the domain."""
    assert uf.brand_lookalike(uf.parse("http://lhdn-refund.site/claim")) == 1
    assert uf.brand_lookalike(uf.parse("http://kwsp-semakan.top/login")) == 1
    assert uf.brand_lookalike(uf.parse("http://tng-ewallet-reload.xyz/topup")) == 1


def test_brand_lookalike_ignores_the_genuine_sites() -> None:
    """If this fires on the real bank, the feature is worse than useless."""
    for url in [
        "https://www.poslaju.com.my/track-trace/",
        "https://www.maybank2u.com.my/home/m2u/common/login.do",
        "https://www.cimbclicks.com.my/",
        "https://www.tnb.com.my/",
        "https://shopee.com.my/",
    ]:
        assert uf.brand_lookalike(uf.parse(url)) == 0, url


def test_an_institutional_suffix_needs_no_allowlist_entry() -> None:
    """A scammer cannot register a .gov.my, so the suffix alone is proof."""
    assert uf.brand_lookalike(uf.parse("https://www.hasil.gov.my/en/")) == 0
    assert uf.brand_lookalike(uf.parse("https://www.kwsp.gov.my/")) == 0
    assert uf.brand_lookalike(uf.parse("https://www.jpj.gov.my/")) == 0


def test_registrable_domain_extraction() -> None:
    assert uf.parse("https://www.maybank2u.com.my/x").registrable_domain == "maybank2u.com.my"
    assert uf.parse("http://maybank2u.secure-login.xyz/").registrable_domain == "secure-login.xyz"
    assert uf.parse("http://lhdn-refund.site/claim").registrable_domain == "lhdn-refund.site"


@pytest.mark.parametrize(
    "url",
    [
        "http://tng-ewallet-reload.xyz/topup",
        "http://lhdn-refund.site/claim",
        "http://ninjavan-my.delivery-update.xyz/track",
        "http://kwsp-semakan.top/login",
    ],
)
def test_known_malaysian_impersonation_shapes_are_flagged(url: str) -> None:
    f = features(url)

    assert f["brand_lookalike"] == 1.0
    assert f["is_suspicious_tld"] == 1.0


def test_has_sensitive_keyword_in_both_languages() -> None:
    assert uf.has_sensitive_keyword(uf.parse("http://x.xyz/verify/account")) == 1
    assert uf.has_sensitive_keyword(uf.parse("http://x.xyz/sahkan-akaun")) == 1
    assert uf.has_sensitive_keyword(uf.parse("https://poslaju.com.my/track-trace/")) == 0


# ── The vector as a whole ──────────────────────────────────────────────────


def test_extract_features_returns_exactly_the_declared_feature_set() -> None:
    """A feature added to extract_features but not to FEATURE_NAMES would be
    silently dropped at serving time, and vice versa."""
    assert set(features(BENIGN)) == set(uf.FEATURE_NAMES)


def test_feature_names_has_no_duplicates() -> None:
    assert len(uf.FEATURE_NAMES) == len(set(uf.FEATURE_NAMES))


def test_to_vector_matches_the_declared_order() -> None:
    vector = uf.to_vector(features(PHISH))

    assert len(vector) == len(uf.FEATURE_NAMES)
    assert vector[uf.FEATURE_NAMES.index("uses_https")] == 0.0
    assert vector[uf.FEATURE_NAMES.index("is_suspicious_tld")] == 1.0


def test_every_feature_is_numeric() -> None:
    # scikit-learn will accept a bool and silently treat it as a number; being
    # strict here keeps the artefact's dtype predictable.
    assert all(isinstance(value, float) for value in features(PHISH).values())


def test_extraction_is_deterministic() -> None:
    assert features(PHISH) == features(PHISH)


@pytest.mark.parametrize(
    "url",
    ["", "   ", "not a url", "http://", "https://.", "javascript:alert(1)", "x" * 3000],
)
def test_extraction_never_raises_on_hostile_input(url: str) -> None:
    """The FormRequest rejects non-http schemes before this point, but the
    extractor must not be the thing that crashes if one slips through."""
    result = features(url)

    assert set(result) == set(uf.FEATURE_NAMES)


def test_a_phishing_url_scores_higher_than_a_benign_one_on_the_obvious_signals() -> None:
    """Not a model assertion — just a sanity check that the features point the
    way the design intends before any training happens."""
    phish, benign = features(PHISH), features(BENIGN)

    assert phish["is_suspicious_tld"] > benign["is_suspicious_tld"]
    assert phish["brand_token_in_subdomain"] > benign["brand_token_in_subdomain"]
    assert phish["uses_https"] < benign["uses_https"]
    assert phish["has_sensitive_keyword"] >= benign["has_sensitive_keyword"]
