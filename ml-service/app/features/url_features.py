"""Lexical feature extraction for the URL classifier.

Everything here is computed from the URL *string*. Nothing in this module
resolves a hostname, opens a socket, or fetches a page — ScamSense never visits
a submitted URL (AGENTS.md §2, rule 3). Fetching user-supplied URLs from the
server is SSRF, and it is the first thing a security-minded panel probes.

That rule is enforced by a test (`test_url_features.py::test_module_imports_no_
network_library`) rather than left to discipline: it inspects this module's
imports and fails if a networking library appears. `urllib.parse` is permitted
because it is pure string manipulation; `urllib.request` is not.

## On the TLD feature

The ml-endpoint skill suggests one-hot encoding the top-N TLDs. This module
instead uses a curated suspicious-TLD list, which is a deliberate departure.
A one-hot vocabulary fitted on training data has to be persisted, versioned and
consulted at serving time, and it needs a policy for TLDs never seen during
training — three new ways for training and serving to disagree. A fixed list is
stable, needs no fitting, and stays interpretable, which matters here because
these features surface directly to users as indicators. See docs/adr/0003.
"""

from __future__ import annotations

import math
import re
from collections import Counter
from dataclasses import dataclass
from urllib.parse import parse_qsl, urlsplit

# ── Reference data ─────────────────────────────────────────────────────────
#
# Formatting is suspended across these tables: they are grouped by meaning and
# read far better packed than exploded to one entry per line.
# fmt: off

#: TLDs with a well-documented skew towards abuse, largely because they are
#: free or near-free to register. Not evidence on its own — plenty of
#: legitimate sites use .xyz — which is why it is one feature among many.
SUSPICIOUS_TLDS: frozenset[str] = frozenset(
    {
        "xyz", "top", "tk", "ml", "ga", "cf", "gq", "buzz", "click", "link",
        "work", "live", "online", "site", "website", "space", "icu", "cyou",
        "rest", "quest", "sbs", "cfd", "monster", "bar", "download", "loan",
    }
)

#: URL shorteners. These are not malicious, but they hide the destination,
#: which means the lexical signal a human or a model would otherwise read is
#: simply absent. Worth flagging to the user for exactly that reason.
KNOWN_SHORTENERS: frozenset[str] = frozenset(
    {
        "bit.ly", "tinyurl.com", "goo.gl", "t.co", "ow.ly", "is.gd", "buff.ly",
        "cutt.ly", "rb.gy", "shorturl.at", "tiny.cc", "rebrand.ly", "s.id",
        "bit.do", "v.gd", "t.ly", "shorte.st", "adf.ly",
    }
)

#: Brand tokens that Malaysian phishing campaigns imitate: banks, e-wallets,
#: couriers, telcos and government services. A lookalike host puts one of these
#: in a subdomain or a hyphenated label rather than in the registrable domain —
#: `maybank2u.secure-login.xyz` rather than `maybank2u.com.my`.
MALAYSIAN_BRAND_TOKENS: frozenset[str] = frozenset(
    {
        # Banks
        "maybank", "maybank2u", "mbb", "cimb", "cimbclicks", "publicbank",
        "pbebank", "rhb", "hongleong", "hlb", "ambank", "bankislam", "bsn",
        "affinbank", "ocbc", "uob", "hsbc", "standardchartered",
        # E-wallets and payments
        "touchngo", "tng", "boost", "grabpay", "shopeepay", "duitnow", "fpx",
        # Marketplaces and logistics
        "shopee", "lazada", "grab", "poslaju", "pos", "jtexpress", "ninjavan",
        "dhl", "gdex", "citylink", "flashexpress",
        # Government and utilities
        "lhdn", "hasil", "jpj", "kwsp", "epf", "perkeso", "socso", "myeg",
        "imigresen", "jpn", "tnb", "syabas", "padu",
        # Telcos and media
        "maxis", "celcom", "digi", "umobile", "unifi", "astro", "yes",
    }
)

#: Registrable domains the brands above actually operate. A URL may mention a
#: brand freely as long as it lives on one of these; anywhere else, mentioning
#: the brand is the definition of a lookalike.
#:
#: This list is the counterpart to MALAYSIAN_BRAND_TOKENS and must be kept in
#: step with it: a token without a corresponding legitimate domain makes the
#: real site look like an impersonator.
LEGITIMATE_BRAND_DOMAINS: frozenset[str] = frozenset(
    {
        # Banks
        "maybank2u.com.my", "maybank.com", "maybank2u.com", "cimb.com.my",
        "cimbclicks.com.my", "cimbbank.com.my", "publicbank.com.my",
        "pbebank.com", "rhbgroup.com", "rhbbank.com.my", "hlb.com.my",
        "hongleongconnect.my", "ambank.com.my", "amonline.com.my",
        "bankislam.com", "bankislam.biz", "bsn.com.my", "affinbank.com.my",
        "affinalways.com", "ocbc.com.my", "uob.com.my", "hsbc.com.my",
        "sc.com",
        # E-wallets and payments
        "touchngo.com.my", "tngdigital.com.my", "myboost.com.my",
        "duitnow.my", "paynet.my",
        # Marketplaces and logistics
        "shopee.com.my", "lazada.com.my", "grab.com", "poslaju.com.my",
        "pos.com.my", "jtexpress.my", "ninjavan.co", "dhl.com", "gdexpress.com",
        "citylinkexpress.com", "flashexpress.my",
        # Government and utilities
        "myeg.com.my", "tnb.com.my", "mytnb.com.my", "syabas.com.my",
        # Telcos and media
        "maxis.com.my", "celcom.com.my", "digi.com.my", "u.com.my",
        "unifi.com.my", "tm.com.my", "astro.com.my",
    }
)

#: Suffixes only an accredited institution can register, so a brand token under
#: one of them is that institution rather than an impersonator. This is a
#: structural guarantee rather than a list to maintain: a scammer cannot buy a
#: `.gov.my` domain.
INSTITUTIONAL_SUFFIXES: frozenset[str] = frozenset(
    {"gov.my", "edu.my", "mil.my", "gov.uk", "gov.sg", "edu.sg"}
)

#: Suffixes that are effectively public registries, so the label before them is
#: the registrable domain rather than a subdomain. Without this, every
#: `something.com.my` would score one subdomain too many — which would bias the
#: model against Malaysian sites specifically.
MULTI_PART_SUFFIXES: frozenset[str] = frozenset(
    {
        "com.my", "net.my", "org.my", "gov.my", "edu.my", "mil.my", "name.my",
        "com.sg", "com.au", "co.uk", "org.uk", "gov.uk", "ac.uk", "co.id",
        "co.th", "com.ph", "com.hk", "co.jp", "com.cn", "com.br",
    }
)

# fmt: on

_IPV4_RE = re.compile(r"^\d{1,3}(?:\.\d{1,3}){3}$")

#: The ordered feature vector. Training writes this to feature_list.json and
#: serving asserts against it, so a reordering cannot silently mismatch the
#: model's expectations.
FEATURE_NAMES: list[str] = [
    "url_length",
    "host_length",
    "path_length",
    "path_depth",
    "query_param_count",
    "subdomain_count",
    "hyphen_count",
    "dot_count",
    "digit_ratio",
    "host_entropy",
    "path_entropy",
    "uses_https",
    "host_is_ip_literal",
    "has_punycode",
    "has_at_symbol",
    "has_double_slash_in_path",
    "has_port",
    "is_known_shortener",
    "is_suspicious_tld",
    "brand_token_in_subdomain",
    "brand_lookalike",
    "has_sensitive_keyword",
    "longest_token_length",
]

#: Words that cluster in credential-harvesting paths.
# fmt: off
SENSITIVE_KEYWORDS: frozenset[str] = frozenset(
    {
        "login", "signin", "verify", "verification", "secure", "account",
        "update", "confirm", "password", "banking", "wallet", "payment",
        "billing", "invoice", "suspend", "unlock", "recover", "authenticate",
        "sahkan", "pengesahan", "akaun", "bayar", "kemaskini",
    }
)
# fmt: on


@dataclass(frozen=True)
class ParsedUrl:
    """A URL decomposed once, so feature functions need not re-parse it.

    Frozen so a feature function cannot mutate shared state — the functions
    below are pure, which is what makes them individually testable and what
    lets training and serving share them without coordination.
    """

    raw: str
    scheme: str
    host: str
    port: int | None
    path: str
    query: str
    fragment: str

    @property
    def tld(self) -> str:
        """The final label of the host, lower-cased. Empty for IP literals."""
        if not self.host or host_is_ip_literal(self):
            return ""
        parts = self.host.rsplit(".", 1)
        return parts[-1] if len(parts) == 2 else ""

    @property
    def registrable_suffix(self) -> str:
        """The public suffix, accounting for two-part suffixes like `com.my`."""
        labels = self.host.split(".")
        if len(labels) >= 2:
            candidate = ".".join(labels[-2:])
            if candidate in MULTI_PART_SUFFIXES:
                return candidate
        return labels[-1] if labels else ""

    @property
    def registrable_domain(self) -> str:
        """The domain someone actually registered, suffix included.

        `www.maybank2u.com.my` → `maybank2u.com.my`
        `maybank2u.secure-login.xyz` → `secure-login.xyz`
        """
        if not self.host or host_is_ip_literal(self):
            return ""

        suffix = self.registrable_suffix
        suffix_label_count = len(suffix.split(".")) if suffix else 0
        labels = self.host.split(".")
        remaining = labels[: len(labels) - suffix_label_count]

        if not remaining:
            return suffix

        return f"{remaining[-1]}.{suffix}" if suffix else remaining[-1]

    @property
    def subdomain_labels(self) -> list[str]:
        """Host labels sitting left of the registrable domain.

        For `login.maybank2u.com.my` the suffix is `com.my`, the registrable
        domain is `maybank2u`, and this returns `["login"]`.
        """
        if not self.host or host_is_ip_literal(self):
            return []

        suffix = self.registrable_suffix
        suffix_label_count = len(suffix.split(".")) if suffix else 0
        labels = self.host.split(".")

        # Drop the suffix labels, then the registrable domain label itself.
        remaining = labels[: len(labels) - suffix_label_count]
        return remaining[:-1] if len(remaining) > 1 else []


def parse(url: str) -> ParsedUrl:
    """Decompose a URL string. Never touches the network.

    A URL with no scheme is treated as http, because users paste bare hosts
    constantly and refusing to parse them would simply lose the submission.
    """
    candidate = url.strip()

    if "://" not in candidate:
        candidate = f"http://{candidate}"

    split = urlsplit(candidate)

    try:
        port = split.port
    except ValueError:
        # A malformed port ("http://x:99999999") raises rather than returning
        # None. Treat it as absent; the raw string still feeds every other
        # feature, so the submission is not lost.
        port = None

    return ParsedUrl(
        raw=url.strip(),
        scheme=split.scheme.lower(),
        host=(split.hostname or "").lower(),
        port=port,
        path=split.path,
        query=split.query,
        fragment=split.fragment,
    )


# ── Individual features ────────────────────────────────────────────────────
#
# Each is a pure function of a ParsedUrl and each has its own unit test with a
# malicious and a benign example.


def url_length(u: ParsedUrl) -> int:
    """Phishing URLs run longer: padding, redirect chains, tracking ids."""
    return len(u.raw)


def host_length(u: ParsedUrl) -> int:
    return len(u.host)


def path_length(u: ParsedUrl) -> int:
    return len(u.path)


def path_depth(u: ParsedUrl) -> int:
    """Number of non-empty path segments."""
    return len([segment for segment in u.path.split("/") if segment])


def query_param_count(u: ParsedUrl) -> int:
    return len(parse_qsl(u.query, keep_blank_values=True))


def subdomain_count(u: ParsedUrl) -> int:
    """`maybank.com.secure-login.xyz` stacks subdomains to look legitimate."""
    return len(u.subdomain_labels)


def hyphen_count(u: ParsedUrl) -> int:
    """Lookalike domains hyphenate what the real brand does not."""
    return u.host.count("-")


def dot_count(u: ParsedUrl) -> int:
    return u.host.count(".")


def digit_ratio(u: ParsedUrl) -> float:
    """Proportion of digits across the whole URL. High in DGA-style hosts."""
    if not u.raw:
        return 0.0
    return sum(character.isdigit() for character in u.raw) / len(u.raw)


def host_entropy(u: ParsedUrl) -> float:
    """Shannon entropy of the host, in bits per character.

    Algorithmically generated hosts (`x7f3kq9z.example`) score high; words
    score low. Weak alone, useful combined with length and digit ratio.
    """
    return _shannon_entropy(u.host)


def path_entropy(u: ParsedUrl) -> float:
    return _shannon_entropy(u.path)


def uses_https(u: ParsedUrl) -> int:
    """Weak on its own — phishing sites get free certificates too — but a
    plain-http login page is still worth surfacing to the user."""
    return int(u.scheme == "https")


def host_is_ip_literal(u: ParsedUrl) -> int:
    """A bare IP address as host. Legitimate brands do not do this."""
    host = u.host if isinstance(u, ParsedUrl) else u
    if _IPV4_RE.match(host):
        return int(all(0 <= int(octet) <= 255 for octet in host.split(".")))
    # urlsplit strips the brackets from an IPv6 literal.
    return int(":" in host)


def has_punycode(u: ParsedUrl) -> int:
    """`xn--` marks an internationalised domain, used for homograph attacks
    where Cyrillic characters render as Latin ones."""
    return int(any(label.startswith("xn--") for label in u.host.split(".")))


def has_at_symbol(u: ParsedUrl) -> int:
    """Everything before `@` in the authority is credentials, not the host —
    `http://maybank2u.com.my@evil.example` goes to evil.example."""
    authority = u.raw.split("://", 1)[-1].split("/", 1)[0]
    return int("@" in authority)


def has_double_slash_in_path(u: ParsedUrl) -> int:
    """A classic open-redirect trick: `/redirect//evil.example`."""
    return int("//" in u.path)


def has_port(u: ParsedUrl) -> int:
    """An explicit non-standard port is unusual for a consumer brand."""
    return int(u.port is not None and u.port not in (80, 443))


def is_known_shortener(u: ParsedUrl) -> int:
    host = u.host.removeprefix("www.")
    return int(host in KNOWN_SHORTENERS)


def is_suspicious_tld(u: ParsedUrl) -> int:
    return int(u.tld in SUSPICIOUS_TLDS)


def brand_token_in_subdomain(u: ParsedUrl) -> int:
    """A Malaysian brand name appearing in a subdomain rather than the
    registrable domain — `maybank2u.secure-login.xyz`."""
    return int(any(_contains_brand_token(label) for label in u.subdomain_labels))


def brand_lookalike(u: ParsedUrl) -> int:
    """A Malaysian brand is invoked by a domain that brand does not operate.

    This is the feature that actually catches impersonation, and it is defined
    by exclusion rather than by pattern. Checking only subdomains and paths
    misses the commonest shape of all — registering the brand name *as* the
    domain: `lhdn-refund.site`, `kwsp-semakan.top`, `tng-ewallet-reload.xyz`.
    There the token sits in the registrable domain, exactly where it sits on
    the genuine site.

    So the test is not "where does the brand name appear" but "does this URL
    invoke a brand while living somewhere that brand does not control".
    A domain is treated as controlled if it is on LEGITIMATE_BRAND_DOMAINS, or
    if its suffix is one only an accredited institution can register.

    The obvious cost is that the allowlist needs maintaining, and a brand
    absent from it will be flagged on its own site. That is the safer direction
    for the error, and it is visible rather than silent.
    """
    if not _mentions_brand(u):
        return 0

    return int(not _is_brand_operated_domain(u))


def has_sensitive_keyword(u: ParsedUrl) -> int:
    """Credential-harvesting vocabulary in the path or query, in English or
    Bahasa Malaysia."""
    haystack = f"{u.path} {u.query}".lower()
    return int(any(keyword in haystack for keyword in SENSITIVE_KEYWORDS))


def longest_token_length(u: ParsedUrl) -> int:
    """Longest alphanumeric run in the path — long random tokens mark
    generated one-off phishing pages."""
    tokens = re.split(r"[^A-Za-z0-9]+", u.path)
    return max((len(token) for token in tokens), default=0)


# ── Aggregation ────────────────────────────────────────────────────────────


def extract_features(url: str) -> dict[str, float]:
    """Compute the full feature vector for one URL.

    Returns a mapping rather than a bare list so callers cannot silently
    depend on positional order; use :func:`to_vector` when an ordered list is
    required.
    """
    u = parse(url)

    return {
        "url_length": float(url_length(u)),
        "host_length": float(host_length(u)),
        "path_length": float(path_length(u)),
        "path_depth": float(path_depth(u)),
        "query_param_count": float(query_param_count(u)),
        "subdomain_count": float(subdomain_count(u)),
        "hyphen_count": float(hyphen_count(u)),
        "dot_count": float(dot_count(u)),
        "digit_ratio": digit_ratio(u),
        "host_entropy": host_entropy(u),
        "path_entropy": path_entropy(u),
        "uses_https": float(uses_https(u)),
        "host_is_ip_literal": float(host_is_ip_literal(u)),
        "has_punycode": float(has_punycode(u)),
        "has_at_symbol": float(has_at_symbol(u)),
        "has_double_slash_in_path": float(has_double_slash_in_path(u)),
        "has_port": float(has_port(u)),
        "is_known_shortener": float(is_known_shortener(u)),
        "is_suspicious_tld": float(is_suspicious_tld(u)),
        "brand_token_in_subdomain": float(brand_token_in_subdomain(u)),
        "brand_lookalike": float(brand_lookalike(u)),
        "has_sensitive_keyword": float(has_sensitive_keyword(u)),
        "longest_token_length": float(longest_token_length(u)),
    }


def to_vector(features: dict[str, float]) -> list[float]:
    """Order a feature mapping according to :data:`FEATURE_NAMES`."""
    return [features[name] for name in FEATURE_NAMES]


# ── Helpers ────────────────────────────────────────────────────────────────


def _shannon_entropy(text: str) -> float:
    if not text:
        return 0.0

    counts = Counter(text)
    length = len(text)

    return -sum((count / length) * math.log2(count / length) for count in counts.values())


def _mentions_brand(u: ParsedUrl) -> bool:
    """Whether a Malaysian brand token appears anywhere in the URL."""
    return _contains_brand_token(f"{u.host} {u.path} {u.query}")


def _is_brand_operated_domain(u: ParsedUrl) -> bool:
    """Whether the registrable domain is one a real brand operates.

    Two ways to qualify. Either the domain is explicitly allowlisted, or its
    suffix is one only an accredited institution can register — a scammer
    cannot buy a `.gov.my`, so `hasil.gov.my` needs no allowlist entry and
    neither does any future government service.
    """
    if u.registrable_suffix in INSTITUTIONAL_SUFFIXES:
        return True

    return u.registrable_domain in LEGITIMATE_BRAND_DOMAINS


def _contains_brand_token(text: str) -> bool:
    """Whether any Malaysian brand token appears in the text.

    Splits on non-alphanumeric characters first so that `maybank2u-verify`
    matches on the `maybank2u` token, while avoiding the substring false
    positives a plain `in` check would produce.
    """
    lowered = text.lower()
    tokens = set(re.split(r"[^a-z0-9]+", lowered))

    if tokens & MALAYSIAN_BRAND_TOKENS:
        return True

    # Also catch tokens concatenated with other words, e.g. `maybanksecure`.
    return any(brand in lowered for brand in MALAYSIAN_BRAND_TOKENS if len(brand) >= 5)
