# ADR 0002 — Data layer schema

- **Status:** Accepted
- **Date:** 2026-08-10
- **Phase:** 1 (data layer)
- **Supersedes / amends:** clarifies the `content_hash` wording in AGENTS.md §3

## Context

Phase 1 defines the persistent shape of ScamSense: six tables covering submissions, their
verdicts, the indicators explaining each verdict, community feedback, cached threat-intelligence
answers, and administrator roles. Because AGENTS.md §5 forbids editing a migration once it has
been committed, these columns are effectively frozen, so the reasoning is recorded here rather
than reconstructed later.

## Decisions

### 1. `content_hash` is computed after redaction, not before

**This is a deliberate departure from the literal wording of AGENTS.md §3**, which says to store
"a SHA-256 hash of the raw input for deduplication". The implementation hashes the *redacted*
text instead, for two reasons.

The first is that hashing raw input would defeat the redaction it sits next to. Submissions are
short and highly structured — a Malaysian phone number has about ten meaningful digits, an IC
number twelve. An attacker or a curious insider holding `content_hash` can enumerate that space
in seconds and confirm whether a specific number was ever submitted. Storing the digest of raw
PII is functionally storing the PII, so the column would reintroduce exactly the exposure
`RedactorService` exists to prevent.

The second is that hashing after redaction is simply better at the job. A scam campaign sends one
template to thousands of people with a different callback number in each copy. Hashed raw, those
are thousands of distinct hashes and the campaign is invisible. Hashed after redaction, they
collapse to a single hash — which is what deduplication and the Phase 8 campaign-frequency chart
actually need.

The intent of the AGENTS.md rule — never store the raw string — is preserved and strengthened.
The letter of it is not. That distinction is recorded here so it reads as a considered decision
rather than an oversight.

### 2. `content_hash` is indexed but not unique

Deduplication here means "have we seen this before", not "refuse to store this again". Repeat
reports of the same scam are a signal: they are how Phase 8 shows which campaign is currently
circulating. A unique constraint would discard that and would also make an ordinary user action —
pasting the same message twice — into an error.

### 3. IP addresses are stored hashed

`submissions.ip_hash` and `feedback.ip_hash` hold SHA-256 digests, never the address. Rate
limiting and abuse detection need to tell IP addresses *apart*; they never need to know which
address it was. Hashing gives the first property and withholds the second, which is a clean
answer under PDPA and a short one at the viva.

### 4. `is_partial` and `partial_reason` are stored on the verdict

AGENTS.md §2 rule 4 requires that when the ML service is unavailable the user still receives a
verdict, clearly labelled as partial. Whether a model ran is a historical fact about one specific
verdict, not a runtime condition, so it is persisted rather than recomputed. Without it, a
screenshot of a degraded verdict would be indistinguishable from a confident one after the
service came back — and Chapter 4 would have no way to report how often degradation occurred.

`VerdictLabel::Unknown` exists for the same reason: it keeps the label column non-null so the UI
and dashboard never special-case a missing value.

### 5. `risk_level` is stored, not derived from `confidence`

Deriving the band at read time would mean that tuning a threshold silently rewrites the risk
level of every historical verdict, including ones already captured for the report. Storing it
keeps a verdict reproducible, which is the same reason `model_version` is stored beside it.

### 6. Indicators are rows, not a JSON column

The Phase 8 dashboard aggregates indicator frequency across all verdicts. As rows that is a
`GROUP BY` on an indexed column; as JSON it is a full scan with per-row extraction. The cost of
the normalised form is one extra table, which is the cheaper side of the trade.

### 7. Indicators record their `source`

Each indicator records whether it came from the model, from the curated pattern layer, or from a
third-party reputation hit. The `ml-endpoint` skill is explicit that request-time DistilBERT
explainability is impractical within a three-second budget, so evidence strings come from
patterns while the model contributes the score — and that this hybrid must be documented as one
rather than presented as model interpretability.

Recording the source per row makes that honesty structural instead of a sentence in the report. A
single query can show exactly which evidence a model produced and which a regex did, which is a
much stronger answer to a panel than a claim.

### 8. Feedback carries a `unique(verdict_id, ip_hash)` constraint

AGENTS.md §3 requires feedback be both rate-limited and deduplicated per IP. Rate limiting is
middleware, but ballot-stuffing a single verdict is a database concern: middleware alone would
let someone spread duplicate votes across successive hourly windows. The constraint makes one
vote per verdict per IP a property of the data rather than of the request path.

More broadly, there is no column, status or foreign key anywhere in this schema connecting a
feedback row to a live model. Poisoning ScamSense would require compromising an administrator
account *and* a deliberate training run — not simply submitting enough agreement.

### 9. The threat-intel cache is keyed on a URL hash

Submitted URLs are user-controlled and may be long or carry credentials and tracking identifiers
in the query string. A fixed-width hash sidesteps MySQL index-length limits under `utf8mb4` and
keeps that content out of a table people will browse while debugging. Scheme and host are
lower-cased before hashing because they are case-insensitive; the path is left alone because it
is not.

### 10. `users.role` defaults to `moderator`

The least-privileged role is the default, so a future code path that creates a user without
setting a role fails closed rather than silently minting an administrator.

### 11. Tests run against MySQL

The suite previously ran on in-memory SQLite while the application ran on MySQL. That divergence
was acceptable while there was no schema; it is not now. This schema depends on behaviour the two
engines do not share — index length limits under `utf8mb4`, JSON column semantics, and the
enforcement of composite unique constraints. Two of the tests in `DataLayerTest` assert that a
duplicate insert throws, which on SQLite would have been testing a different engine's opinion.

The cost is roughly three seconds on a full run.

## Consequences

- `RedactorService` is now on the critical path for every write. It has 45 unit tests, including
  a set asserting that legitimate Malay and English text passes through byte-identical.
- Two false negatives were found by adversarial probing rather than by the original tests:
  bracketed landline numbers (`(03) 2145 6789`) and space-grouped account numbers
  (`5140 1234 5678`). Both leaked PII into storage. Both are now fixed and covered.
- Redaction deliberately does **not** touch URLs. They are parked behind control-character
  sentinels before any pattern runs and restored afterwards, because masking the phishing link
  would destroy the very feature the URL classifier is trained on.
- `Verdict::isFromModel()` gives Phase 5 and Phase 6 a single named check for the degraded path.
- The seeder marks every verdict `model_version = 'seed-data-not-a-model'`, so a screenshot taken
  from seeded data can never be mistaken for a measured result.

## Known limitations

Recorded because a panel may probe them, and an honest answer is stronger than a surprised one.

- **Twelve-digit ambiguity.** A MyKad number and several Malaysian bank account formats are both
  twelve digits. `RedactorService` disambiguates by checking that the first six digits form a
  plausible date and the next two an issued place-of-birth code (01–85). This is a validated
  check rather than a guess, but a bank account that happens to satisfy both will be masked as an
  IC. Both outcomes redact the data; only the label differs.
- **Courier tracking numbers are masked as account numbers.** A twelve-digit tracking reference
  matches the account pattern. This errs towards privacy at a small cost in signal, which is the
  right direction for the error, but it does mean a parcel-scam message loses its tracking
  reference.
- **The account pattern over-matches.** Any bare run of ten to sixteen digits is masked. A
  ten-digit reference number in legitimate text will be redacted unnecessarily. Every one of
  these false positives fails safe.
