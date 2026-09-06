# ADR 0008 — The feedback moderation gate

- **Status:** Accepted
- **Date:** 2026-08-12
- **Phase:** 7 (community reporting loop)
- **Objective:** O3

---

## Context

Objective O3 is a community reporting loop: users report when a verdict looks wrong, and those
reports eventually improve the models. AGENTS.md §3 is blunt about the risk — crowdsourced labels
are a data-poisoning vector, and must never feed a model directly.

The question this record answers is not *whether* to moderate, but **where the guarantee lives**.

## Decision 1 — the gate is a model invariant, not a service convention

`TrainingSample` refuses at the model layer to create a community-origin row unless it references
feedback whose status is `Approved` and records the administrator who approved it. Violations
throw `UnmoderatedPromotionException`.

The alternative — putting the rule in `FeedbackModerationService` and being careful to always go
through it — was rejected. A rule that lives in one service holds exactly until someone writes a
second path to the same table, and the second path always looks reasonable at the time: a bulk
action for a backlog, a seeder for demo data, an import command for a migration, a one-line
`TrainingSample::create()` in tinker while debugging. Each would dismantle the project's central
security claim without anybody intending to.

Putting the invariant on the model means every one of those paths hits it, **including paths not
yet written**. That is the difference between a policy and a control.

The `updating` hook closes the obvious follow-up: insert a `corpus` row, then quietly flip its
origin to `community` afterwards.

### It is enforced by tests that attack it

`tests/Feature/ModerationGateTest.php` attacks the gate from every angle available to a
developer, not just the happy path: direct create with pending feedback, with rejected feedback,
with no feedback, with no approver, via mass assignment, via an origin flip after creation, and
with an unrecognised origin. It also asserts, at the end, that no unmoderated community row
exists in the table by any route.

It further asserts there is **no bulk-approve route**, structurally, so a future convenience
endpoint has to argue with a failing test before it can exist. A button that approves fifty items
at once is a button pressed without reading fifty items, at which point review is decorative.

## Decision 2 — three steps, not one

```
feedback (pending) → approved → exported into a dataset version → trained on
```

Approval marks a row correct. Export stamps it into a numbered dataset version. Training is a
separate deliberate act in `ml-service/`. Nothing collapses these.

The gap between approval and export is the useful one: an administrator can work through a queue
at their own pace without each individual decision silently changing what the next training run
consumes. `dataset_version` being null means "a human said yes, but no model has seen this".

## Decision 3 — the moderator supplies the label, not the reporter

`approve()` requires an explicit `VerdictLabel` and deliberately does not infer one from whether
the user agreed or disputed. The admin form has no default selection, so approving without
deciding is impossible.

This is the substance of the anti-poisoning claim. If a reporter's assertion became the training
label, the crowd would be labelling the dataset by consensus and the moderation queue would be a
formality that merely slowed the attack down. A report is a reason to look; the moderator decides
what is true.

The seeded queue deliberately contains reports where the reporter is *wrong* — "Saya rasa ini
betul sebab ada nama Maybank dalam link tu" — because that is precisely the case review exists
to catch.

## Decision 4 — provenance survives promotion

`training_samples` carries `feedback_id`, `submission_id`, `origin`, `approved_by` and
`approved_at`. Chapter 4 can therefore state exactly which retraining rows came from users and
which from the original corpus, per row, back to the moderation decision and the person who made
it.

Without that, "we retrained on community data" would be unauditable, and a v2 model could not be
described honestly. `origin` answers it in aggregate; `feedback_id` answers it individually.

`unique(feedback_id)` means re-approving cannot duplicate a row.

## Decision 5 — session-scoped feedback, not accounts

Public submission stays login-free: asking a frightened person to register before they can report
a scam would suppress exactly the reports most worth having. So feedback is tied to an anonymous
per-session token, stored hashed on the submission and compared with `hash_equals`.

**What this control does:** stops feedback being posted against arbitrary verdict ids. Without
it, walking ids would let one person flood the queue with assertions about verdicts they never
saw — a bulk-labelling primitive handed to an attacker.

**What it does not do:** it is not authentication. Someone can clear their session and get a new
token, or submit a verdict specifically in order to leave feedback on it. That is accepted: the
design assumes feedback is adversarial and answers that with moderation, not identity. This
control raises the cost of the *easy, high-volume* attack; Decision 1 blocks the *consequential*
one.

Feedback is also rate-limited (5/minute, 5/hour per IP), deduplicated by a
`unique(verdict_id, ip_hash)` database constraint, validated in a FormRequest, and its free-text
comment is redacted before storage — PII must not enter through the comment field having been
kept out of the message field.

## Consequences

- The system can accept community reports without any path by which those reports change model
  behaviour on their own.
- Poisoning ScamSense requires compromising an administrator account *and* a deliberate training
  run — not submitting enough agreement.
- The moderation queue is a real workload: 16 seeded pending items, none approved, because
  approval is a human act that has not happened yet.
- A `Viewer` role was added so that "signed in" and "allowed to moderate" are genuinely different
  states. Without a role that fails the check, the role check on the admin routes was untestable
  and effectively decorative.

## The one path the invariant does not bind

**Added 2026-09-06, after checking rather than assuming.**

The gate lives in `creating` and `updating` hooks on `TrainingSample`, which is what makes it
bind write paths that do not yet exist. It does **not** bind `Model::withoutEvents()`, and
`DatabaseSeeder` uses Laravel's `WithoutModelEvents` trait, which wraps the entire seeder run in
exactly that. Verified empirically: inside `withoutEvents`, a community row with no
`feedback_id` and no `approved_by` is created without complaint.

There is no live bypass. No seeder creates a `TrainingSample`, and `FeedbackQueueSeeder` says so
in its docblock — it seeds pending feedback and no approvals, so the queue screenshot shows work
waiting for a human, which is the honest picture. But the guarantee for that path rests on the
convention holding rather than on the invariant, and the brief was explicit that no route may
promote feedback without review — "not a bulk action, not a seeder, not a convenience method".

Three tests now make the convention checkable: one asserting no seeder file writes to the table,
one asserting a full seeder run leaves no community rows, and one pinning the `withoutEvents`
behaviour as a recorded fact so the gap is reviewable rather than latent.

**The stronger fix, not taken.** A database `CHECK` constraint — `origin <> 'community' OR
(feedback_id IS NOT NULL AND approved_by IS NOT NULL)` — would enforce this below the ORM, where
`withoutEvents` cannot reach, and MySQL 8 enforces those. It is deliberately deferred rather
than rejected: it is a schema change close to a deployment deadline, and it would need the data
dictionary and the ERD updated with it. The residual risk is a *future* seeder, which the source
test catches at the point it would be introduced.

## Two defects this phase surfaced

Recorded because both were silent, and both are the kind a review would not catch by reading.

- **`session_token_hash` was missing from `Submission`'s `Fillable` attribute**, so mass
  assignment dropped it without error. The gate then failed closed — every feedback submission
  returned 403 — which is the safe direction, but the feature would have been entirely
  non-functional in production while looking correct in code review.
- **Two unnamed `throttle:` middlewares on one route share a cache key.** The signature is
  derived from the route and IP, not from the limit, so stacking a per-minute and a per-hour
  throttle made each request increment the same counter twice and throttled at a fraction of the
  intended rate. Both the verification and feedback endpoints now use named rate limiters, which
  include the limiter name in the key.
