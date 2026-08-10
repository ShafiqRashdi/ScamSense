# ADR 0003 — URL lexical features

- **Status:** Accepted (feature extraction). Model **not yet trained** — see "Current state".
- **Date:** 2026-08-10
- **Phase:** 2 (URL classifier)

## Context

The URL classifier scores a submitted link without ever visiting it. Everything it knows comes
from the string. This record covers the 23 features chosen, and three decisions where the
implementation departs from the guidance in the `ml-endpoint` skill.

## Current state — read this before quoting any number

**No URL model has been trained. This repository contains no URL model metrics.**

`training/train_url_model.py` runs end to end and is covered by plumbing tests, but it has never
been executed against a real dataset because no dataset has been obtained. The tests in
`tests/test_train_url_model.py` exercise the pipeline over a few dozen hand-written synthetic
strings; any figure they produce is meaningless and is never written anywhere durable.

`/health` correspondingly reports `null` for the URL model version. The F1 figure objective O1 is
graded against does not exist yet, and inventing one would be worse than not having it.

## Decisions

### 1. Lexical only, enforced by a test rather than by discipline

AGENTS.md §2 rule 3 forbids ScamSense from fetching a submitted URL: no request, no DNS
resolution, no WHOIS lookup. That is an SSRF control and the first thing a security-minded panel
probes.

Rather than rely on nobody ever adding `import requests`, `test_module_imports_no_network_library`
parses `url_features.py` with `ast` and fails the build if a networking module appears in its
imports. `urllib.parse` is permitted because it is pure string manipulation.

Making the rule mechanical means it survives a future contributor who has not read AGENTS.md.

### 2. A curated suspicious-TLD list instead of a fitted one-hot encoding

The `ml-endpoint` skill suggests one-hot encoding the top-N TLDs observed in training. This
implementation uses a fixed curated list of TLDs with a known abuse skew.

A fitted vocabulary has to be persisted alongside the model, versioned with it, loaded at serving
time, and given a policy for TLDs never seen during training — four opportunities for training
and serving to disagree, which is the failure mode the shared-extraction-module rule exists to
prevent. A fixed list needs no fitting and cannot drift.

It also stays interpretable. These features surface to users as indicators, and "this link uses
an unusual domain ending" is explainable in a way that "TLD one-hot index 34" is not.

The cost is that the list needs occasional maintenance as abuse patterns shift, and a genuinely
novel abusive TLD is missed until someone adds it. Accepted: `is_suspicious_tld` is one feature
among 23, not a verdict.

### 3. `brand_lookalike` is defined by exclusion, not by pattern

The first implementation looked for Malaysian brand tokens in subdomains and paths — the
`maybank2u.secure-login.xyz` shape. Adversarial test cases showed it scored **zero** on
`lhdn-refund.site`, `kwsp-semakan.top` and `tng-ewallet-reload.xyz`.

That is the commonest impersonation shape of all. The scammer registers the brand name *as* the
domain, so the token sits exactly where it sits on the genuine site, and any "is the brand in the
wrong position" rule misses it entirely.

The feature was redefined. The question is not *where* the brand name appears but *whether this
URL invokes a brand while living somewhere that brand does not control*:

```
brand_lookalike = mentions_a_brand AND NOT on_a_domain_that_brand_operates
```

A domain qualifies as brand-operated two ways. Either it appears in `LEGITIMATE_BRAND_DOMAINS`,
or its suffix is in `INSTITUTIONAL_SUFFIXES` — a scammer cannot register a `.gov.my`, so
`hasil.gov.my` needs no allowlist entry and neither will any future government service.

The cost is an allowlist to maintain, and a brand missing from it will be flagged on its own
site. That is the safer direction for the error and it is visible rather than silent. Tests
assert the feature scores zero on `maybank2u.com.my`, `cimbclicks.com.my`, `shopee.com.my`,
`tnb.com.my` and three `.gov.my` domains, because a lookalike detector that fires on the real
bank is worse than no detector at all.

### 4. Malaysian two-part suffixes are handled explicitly

Without a public-suffix list, `maybank2u.com.my` parses as having two subdomains — `maybank2u`
and `com` — where `maybank2u.com` would have none. Every Malaysian domain would score
systematically higher on `subdomain_count` than its Western equivalent, and the model would
learn to distrust `.com.my` as such.

`MULTI_PART_SUFFIXES` lists the relevant suffixes so the registrable domain is identified
correctly. A full public-suffix-list dependency would be more complete; a curated list covering
Malaysia and its neighbours is proportionate here and has no runtime cost.

### 5. The threshold is chosen for recall, on validation only

`choose_threshold` maximises F-beta with beta=2 on the **validation** set, weighting recall on
the phishing class four times as heavily as precision. A missed scam costs a user money; a false
positive costs them ten seconds of doubt.

The test set is evaluated once, after the threshold is fixed, and `metrics.json` records both so
the two can be compared. A test asserts the test-set threshold equals the validation-set
threshold, so re-optimising against the reported number fails the build.

### 6. Duplicates are dropped before splitting

`load_dataset` deduplicates on exact URL before any split occurs. The same URL in both train and
test is the single most common way a URL classifier reports a score it has not earned, and public
phishing corpora contain substantial duplication.

## Consequences

- Training and serving share one extraction module, so the training/serving skew that silently
  degrades deployed models cannot arise from two implementations.
- `FEATURE_NAMES` is the single ordered source of truth, written to `feature_list.json` at train
  time. A test asserts `extract_features` returns exactly that set, so adding a feature to one
  and not the other fails rather than silently dropping a column.
- The extractor never raises: hostile input including empty strings, bare words, `javascript:`
  URLs and 3,000-character strings all return a complete feature vector. Validation rejects those
  upstream, but the extractor must not be the component that crashes.

## What Phase 2 still needs

A phishing URL dataset, and a legitimate-URL corpus that is not dominated by a handful of
domains. Until both exist there is no model, and `training/DATASETS.md` records the candidate
sources with the provenance each requires.

Public corpora are English- and Western-heavy, which matters here more than usual: a model
trained only on them will underperform on precisely the Malaysian campaigns this project exists
to catch.
