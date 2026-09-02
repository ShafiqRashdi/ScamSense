# Data dictionary — verified against the migrations

Every table and column below was read out of `backend/database/migrations/` on
2026-08-24. Where the Chapter 4 draft and the schema disagree, the schema is
right: it is what the examiner can run `DESCRIBE` against.

Paste the tables straight into Word. The four columns match the draft's existing
format (Field, Data Type, Constraint, Description), so the styling carries over.

---

## What changed, and why it matters

Seven differences, and three of them are load-bearing arguments rather than
column names.

| Draft | Schema | Consequence |
|---|---|---|
| `verdict_indicators` | **`indicators`** | Name only |
| `threat_intel_lookups` | **`threat_intel_cache`** | Name only |
| `reviewed_by` | **`moderated_by`** | Name only |
| `submissions.language` | **does not exist** | Row must be deleted from Table 4.1 |
| `verdicts.ml_score` + `threat_intel_score` | **one `confidence` DECIMAL(5,4)** | **The paragraph arguing for the separation describes a design that was not built** |
| `training_samples.split` | **does not exist** | **The paragraph about a frozen held-out partition has no column behind it** |
| **Table 4.7 `model_versions`** | **does not exist** | **Delete the table. `verdicts.model_version` is a VARCHAR(64) with no foreign key** |

That last one also fixes the count: the schema has **seven** tables, not eight.
The eighth was `model_versions`.

Three passages in the draft rest on the missing columns and need replacing —
suggested wording is at the end of this file.

---

## Table 4.1 — `submissions`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the submission |
| `type` | VARCHAR(16) | NOT NULL | Content type: `message` or `url`. Cast to an enum in the application, not constrained in the database |
| `content_redacted` | TEXT | NOT NULL | Submitted content after removal of personal data |
| `content_hash` | CHAR(64) | NOT NULL, INDEX | SHA-256 of the **redacted** text. Deliberately not the raw input: a short message hashes to a recoverable value, which would make the column an oracle for the data redaction just removed (ADR 0002) |
| `ip_hash` | CHAR(64) | NULLABLE, INDEX | Hashed client address, supporting rate limiting without storing the address |
| `session_token_hash` | CHAR(64) | NULLABLE, INDEX | Hashed anonymous session identifier, linking a guest to their own verdict so only they can leave feedback on it |
| `created_at` | TIMESTAMP | NULLABLE | Time of submission; basis for the retention period |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

Composite index on (`type`, `created_at`) for dashboard volume charts and the
retention purge. `content_hash` is **not** unique — repeat submissions of the
same scam are a signal worth counting.

---

## Table 4.2 — `verdicts`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the verdict |
| `submission_id` | BIGINT UNSIGNED | FK → `submissions`, CASCADE ON DELETE | The submission assessed |
| `label` | VARCHAR(16) | NOT NULL, INDEX | `scam`, `legitimate` or `unknown` |
| `risk_level` | VARCHAR(16) | NOT NULL, INDEX | Band shown to the user: `high`, `medium`, `low` or `unknown` |
| `confidence` | DECIMAL(5,4) | NOT NULL | The fused score. DECIMAL rather than FLOAT because these values are averaged and compared on the dashboard, and binary floating point makes those comparisons quietly unreliable |
| `model_version` | VARCHAR(64) | NULLABLE, INDEX | Artefact directory name that produced the verdict, e.g. `rf-url-v2.0.0-pathnorm`. **A plain string, not a foreign key** — see the note below |
| `is_partial` | BOOLEAN | NOT NULL, DEFAULT false, INDEX | Whether a dependency was unavailable. Persisted rather than computed, because whether the model ran is a historical fact about this verdict |
| `partial_reason` | VARCHAR(64) | NULLABLE | Which dependency failed, e.g. `ml_service_unavailable` |
| `inference_ms` | INT UNSIGNED | NULLABLE | Round-trip time reported by the ML service, so the latency objective is evidenced from real rows |
| `created_at` | TIMESTAMP | NULLABLE | Time the verdict was produced |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

**On `model_version`.** Traceability is achieved by denormalisation, not by a
join. The string is written once and never updated, so a verdict remains
attributable to a specific artefact even if that artefact is later deleted from
the models directory — which a foreign key to a `model_versions` table would
not survive. The model's own metrics live in the committed
`ml-service/models/<version>/metrics.json`, which is the single source the
dashboard and Chapter 4 both read.

---

## Table 4.3 — `indicators`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the indicator |
| `verdict_id` | BIGINT UNSIGNED | FK → `verdicts`, CASCADE ON DELETE | The verdict this reason belongs to |
| `code` | VARCHAR(64) | NOT NULL, INDEX | Closed enum shared with the Flask service, e.g. `brand_lookalike`. No user-facing text is stored; Laravel maps codes to Malay and English prose |
| `weight` | DECIMAL(5,4) | NOT NULL | The model's learned importance for the feature that fired. Zero for pattern-layer indicators, which have no learned weight |
| `evidence` | VARCHAR(255) | NULLABLE | Short excerpt from the submission showing what triggered it. Redacted on the way in, escaped on render |
| `source` | VARCHAR(16) | NOT NULL, INDEX | `model`, `pattern` or `threat_intel`. Keeps real attributions distinguishable from the curated pattern layer |
| `created_at` | TIMESTAMP | NULLABLE | Time recorded |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

A row per indicator rather than a JSON column on `verdicts`: the dashboard
aggregates indicator frequency, which is a `GROUP BY` on an indexed column here
and a full table scan with JSON extraction there.

---

## Table 4.4 — `threat_intel_cache`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the cached lookup |
| `provider` | VARCHAR(32) | NOT NULL | `google_safe_browsing` or `virustotal` |
| `url_hash` | CHAR(64) | NOT NULL | SHA-256 of the normalised URL. Hashed rather than stored, because submitted URLs are user-controlled and may carry credentials or tracking identifiers |
| `response` | JSON | NOT NULL | The provider's answer, normalised by the client class. JSON because the two providers agree on nothing except the question |
| `is_malicious` | BOOLEAN | NULLABLE | Denormalised from `response` so the common query is an indexed boolean. **Nullable by design**: a provider that failed or has no opinion is not a "no", and conflating those would understate risk |
| `fetched_at` | TIMESTAMP | NOT NULL | When the provider was asked |
| `expires_at` | TIMESTAMP | NOT NULL, INDEX | End of the 24-hour lifetime |
| `created_at` | TIMESTAMP | NULLABLE | Row creation time |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

UNIQUE on (`provider`, `url_hash`) — one cached answer per provider per URL,
which the client upserts against.

---

## Table 4.5 — `feedback`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the report |
| `verdict_id` | BIGINT UNSIGNED | FK → `verdicts`, CASCADE ON DELETE | The verdict being reported on |
| `agrees` | BOOLEAN | NOT NULL | True if the user agrees with the verdict, false if they dispute it |
| `comment` | TEXT | NULLABLE | Optional free text. Redacted before insert and escaped on render |
| `status` | VARCHAR(16) | NOT NULL, DEFAULT `pending` | `pending`, `approved` or `rejected` |
| `ip_hash` | CHAR(64) | NOT NULL | Hashed client address, used for deduplication |
| `moderated_by` | BIGINT UNSIGNED | NULLABLE, FK → `users`, SET NULL ON DELETE | The administrator who decided |
| `moderated_at` | TIMESTAMP | NULLABLE | When the decision was made |
| `moderation_note` | VARCHAR(255) | NULLABLE | Optional note recorded with the decision |
| `created_at` | TIMESTAMP | NULLABLE | Time the report was submitted |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

UNIQUE on (`verdict_id`, `ip_hash`) — one vote per verdict per address. The rate
limit is middleware; ballot-stuffing a single verdict is a database constraint,
because a control that lives only in application code holds until a second write
path is introduced.

---

## Table 4.6 — `training_samples`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the sample |
| `feedback_id` | BIGINT UNSIGNED | NULLABLE, UNIQUE, FK → `feedback`, SET NULL ON DELETE | The approved report this came from. Null for corpus rows. **UNIQUE**, so one report cannot be promoted twice |
| `submission_id` | BIGINT UNSIGNED | NULLABLE, FK → `submissions`, SET NULL ON DELETE | The original submission, where one exists |
| `origin` | VARCHAR(16) | NOT NULL, INDEX | `community` or `corpus`. This is what lets Chapter 4 state exactly how much retraining data came from users |
| `type` | VARCHAR(16) | NOT NULL | `message` or `url` |
| `content` | TEXT | NOT NULL | Already-redacted content, copied from `submissions.content_redacted` |
| `content_hash` | CHAR(64) | NOT NULL, INDEX | SHA-256 for deduplication across the set |
| `label` | VARCHAR(16) | NOT NULL, INDEX | **The moderator's label, not the user's assertion.** A user disputing a verdict is a signal worth reviewing, not a label |
| `approved_by` | BIGINT UNSIGNED | NULLABLE, FK → `users`, SET NULL ON DELETE | The administrator who approved the promotion |
| `approved_at` | TIMESTAMP | NULLABLE | When approval happened |
| `dataset_version` | VARCHAR(32) | NULLABLE, INDEX | The exported dataset version this row belongs to. Null until deliberately exported |
| `exported_at` | TIMESTAMP | NULLABLE | When the row was exported into a dataset version |
| `created_at` | TIMESTAMP | NULLABLE | Row creation time |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

Composite index on (`origin`, `label`) for the provenance split on the
dashboard.

**There is no `split` column.** Train/validation/test assignment is not a
property of a database row; it is held in
`ml-service/models/<version>/split_indices.json`, committed alongside the
artefact. See the replacement paragraph below.

---

## Table 4.7 — `users`

| Field | Data Type | Constraint | Description |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | Unique identifier for the account |
| `name` | VARCHAR(255) | NOT NULL | Display name, shown against moderation decisions |
| `email` | VARCHAR(255) | NOT NULL, UNIQUE | Sign-in identifier |
| `role` | VARCHAR(16) | NOT NULL, DEFAULT `moderator`, INDEX | `admin` or `moderator`. Checked on every admin route in addition to authentication |
| `password` | VARCHAR(255) | NOT NULL | Bcrypt hash |
| `remember_token` | VARCHAR(100) | NULLABLE | Laravel's persistent-login token |
| `created_at` | TIMESTAMP | NULLABLE | Account creation time |
| `updated_at` | TIMESTAMP | NULLABLE | Time of last modification |

Administrative accounts only. **No column here holds data about a member of the
public** — there is no public registration, and no personal identifier is
collected from a submitter at any point. There is deliberately no
`email_verified_at`: accounts are created by a seeder, not by self-registration,
so there is no address to verify.

Laravel's framework tables (`sessions`, `password_reset_tokens`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`) exist but carry no
application data and are conventionally excluded from a data dictionary.

---

## Replacement passages

Three paragraphs in the draft argue for columns that were never built. Each
argument is sound — the implementation simply solved the same problem
differently, and the substituted wording says how.

### Replacing the `ml_score` / `threat_intel_score` paragraph (§4.3, after Table 4.2)

> A verdict stores a single fused `confidence` value together with the
> `model_version` that produced it. The contribution of each input remains
> recoverable without a second score column: threat intelligence contributes
> through the `indicators` table, where a `threat_intel_hit` row carries
> `source = threat_intel`, and the verified-institution gate records itself the
> same way. Because indicators are rows rather than a blended number, a verdict
> can be decomposed after the fact — which score column pairs could not have
> done for the gate, since capping a verdict is not a score at all.

### Replacing the `split` paragraph (§4.3, after Table 4.6)

> Train, validation and test assignment is not stored in the database. It lives
> in `split_indices.json`, committed beside each model artefact, and is written
> once when a model is trained. Holding it with the artefact rather than on the
> row is what keeps a held-out partition genuinely held out: the test set
> belongs to a specific trained model, and a later model trained on a grown
> corpus has its own. A column on `training_samples` would have implied one
> global split shared across model versions, which is precisely the arrangement
> that makes comparisons between versions invalid.

### Replacing the ERD description (§4.3.3, Figure 4.7)

> Figure 4.7 shows the relationships between the seven application tables. A
> submission has many verdicts, permitting the same input to be reassessed by a
> later model version. A verdict has many indicators, which supply the
> explanation presented to the user, and may have many feedback items.
>
> Traceability from a verdict back to the model that produced it is achieved by
> denormalisation rather than a relationship: `verdicts.model_version` stores
> the artefact's version string directly. There is no `model_versions` table,
> and this is deliberate — the string is written once and never updated, so a
> verdict stays attributable even to an artefact later removed from the models
> directory, which a foreign key would not survive. Each artefact's own metrics
> are committed as `metrics.json` alongside it, which is the single file both
> the administrative dashboard and this report read, so the two cannot drift
> apart.
>
> The `threat_intel_cache` table has no foreign key relationship to submissions.
> This is intentional: a cached lookup is keyed on a hash of the URL rather than
> on any particular submission, so that one cached result serves every
> subsequent submission of the same address.

### Also update, §4.3 opening

> The schema comprises **seven** application tables.
