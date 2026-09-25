# ADR 0010 — Message indicators are a pattern layer, and several URL features are deliberately never shown

**Status:** Accepted
**Date:** 2026-08-18
**Supersedes nothing. Extends `0003` (URL features) and `0006` (artefact audit).**

---

## Context

Two questions arrived together while wiring the message model into the serving path, and they
turn out to be the same question: **what earns the right to be shown to a user as a reason?**

### Question 1 — how do you explain a character n-gram model?

The message classifier is TF-IDF over `char_wb` 2–5 grams into a Logistic Regression. Its
coefficients are per-n-gram. The honest rendering of "why did you say scam" would be a list of
character fragments — `"sahk"`, `" seg"`, `"m50"` — which is useless to a reader and actively
misleading if presented as reasoning.

### Question 2 — why does the medium band often show nothing?

A design review found that a medium verdict frequently rendered "parts of this link look odd"
with no indicators listed at all. Investigation showed that **59.8% of the URL model's importance
mass had no indicator rule**, including its second and third most important features
(`host_length` 0.1423, `url_length` 0.1072).

The obvious fix — add a rule for each — is wrong, and the reason is the substance of this ADR.

---

## Decision

### 1. Message indicators are a curated pattern layer, labelled as such

A bilingual keyword layer runs *alongside* the classifier, emitting four codes:
`urgency_language`, `prize_claim`, `credential_request`, `payment_demand`. It is bilingual by
necessity — the corpus is 417 Malay, 81 code-switched — since an English-only pattern set would
stay silent on the majority of real submissions.

**Every message indicator carries `source: "pattern"` and `weight: 0.0`.** The model contributes
the score; the patterns contribute evidence. A test asserts this and will fail if a message
indicator is ever emitted as `source: "model"`.

This is a hybrid, and calling it one is the point. The `ml-endpoint` skill anticipated exactly
this trade-off and required it be documented honestly rather than dressed up as interpretability.

### 2. A feature earns a place in the UI by discriminating, not by mattering to the model

Measured on a 25,000-URL sample against a **49.7% base rate**, the uncovered features split
cleanly into two groups:

| Feature | Threshold | Precision above it | Fires on | Surfaced? |
|---|---:|---:|---:|:--:|
| `host_length` | > 29 | **84.7%** | 14.2% | yes |
| `hyphen_count` | > 1 | **85.4%** | 6.8% | yes |
| `digit_ratio` | > 0.15 | 62.9% | 13.6% | no |
| `url_length` | > 93 | **52.3%** | 10.3% | no |
| `path_depth` | > 3 | 39.5% | 7.9% | no |

`url_length` fires at 52.3% precision against a 49.7% base rate. **Showing "this address is
unusually long" on a coin-flip would manufacture false confidence in a reader who has no way to
audit the claim.** No threshold rescues it: at the legitimate 99th percentile it drops to 41.5%,
*below* the base rate.

This is the artefact audit from ADR 0006 reappearing with a practical consequence. That audit
found `url_length` has a label AUC of 0.5896 against a **source** AUC of 0.8978 — it mostly
identifies which dataset a row came from. It is simultaneously load-bearing for the model
(dropping it cost 8.9 F1 points) and near-useless as a reason to give a person.

**A model may combine weak features. An explanation shown to a human may not.** The two have
different standards of evidence, and `ml-service/app/indicators.py` records the excluded
features in a `NOT_SURFACED` tuple with the measurements, so the omission reads as a decision
rather than an oversight.

### 3. When nothing fires, say so

After adding the two defensible rules, a genuine gap remains: `https://www.utm.my/about/` scores
0.3179 against a 0.3105 threshold — a real medium verdict — and still emits zero indicators,
because the features driving it are precisely the ones held back above.

The indicators card now renders unconditionally, with an explicit line when the list is empty.
Hiding the section left the reader with "parts of this look odd" and nothing named, which reads
as a list that failed to load rather than as a finding.

---

## Consequences

**Good.** The medium band — the band 7 of 17 genuine Malaysian sites land in, and the state most
users meet on a legitimate link — now always says something. Two genuinely informative signals at
~85% precision are surfaced that previously were not. The high band gained coverage: a phishing
probe URL went from 2 indicators to 4.

**Accepted cost.** The system will sometimes show fewer reasons than the model "used". That is
deliberate and defensible: a user cannot verify a claim about entropy or length distributions,
and an unverifiable reason at chance-level precision is worse than an honest "nothing specific
stood out".

**Residual risk.** The `NOT_SURFACED` list was measured on the same corpus whose composition ADR
0006 criticises. If a future corpus is more representative, some of those features may become
genuinely discriminative and worth surfacing. The measurement, not the list, is the thing to
re-run — `training/audit_url_artefacts.py` and the threshold sweep in this ADR are both
reproducible.

**Not addressed here.** Whether the *message* pattern layer should influence the verdict rather
than only annotate it. The generalisation probe (`training/message_probe.py`) shows the model
missing 6 of 12 hand-written scams, several of which fire two or more patterns. Letting strong
pattern evidence cap a "no warning signs" verdict at "not certain" would be a safety mitigation
in the shape of the verified-domain gate in ADR 0007 — an external rule gating a model output,
never a claim of improved model accuracy. That decision is deferred, not declined.
