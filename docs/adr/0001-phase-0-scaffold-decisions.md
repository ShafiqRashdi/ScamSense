# ADR 0001 — Phase 0 scaffold decisions

- **Status:** Accepted
- **Date:** 2026-08-10
- **Phase:** 0 (repository scaffold)

## Context

Work began from a partially scaffolded repository: a Laravel installation with Breeze
authentication sat at the repository root, and there was no ML service. Several things in it
disagreed with the rules in `AGENTS.md`, so Phase 0 was as much repair as it was scaffolding.
This record exists because each of the disagreements had to be resolved one way or the other,
and a panel is entitled to ask why.

## Decisions

### 1. The Laravel application moved into `backend/`

`AGENTS.md` §2 specifies `backend/` and `ml-service/` as siblings. The installation was at the
repository root instead, which would have forced `ml-service/` to nest inside a Laravel project.

The move was made before the first commit, when it cost one rename. Deferring it would have
meant rewriting paths across the documentation, the four agent skills, every phase prompt, and
any tooling configuration written in the meantime.

### 2. Bootstrap 5 replaced Tailwind CSS

Breeze installs a Tailwind-based UI by default. `AGENTS.md` §2, the `laravel-feature` skill, and
the Phase 6 brief all specify Bootstrap 5, so the toolchain was switched: Tailwind, its PostCSS
configuration, and the fifteen generated Tailwind Blade components were removed, and the two
layouts plus the login view were rewritten against Bootstrap.

Bootstrap is compiled from Sass source rather than consumed as prebuilt CSS. That is a Phase 6
enabler: the target user is a non-technical, often older Malaysian adult on a mid-range Android
phone, so the type scale and touch-target sizes need raising above Bootstrap's defaults. Doing
that by overriding Sass variables before the import produces one coherent stylesheet, whereas
patching with utility classes after the fact does not.

**Trade-off.** Tailwind's utility model would have made bespoke layout marginally faster to
write. Bootstrap was chosen because its component defaults — form validation states, alerts,
focus rings — already satisfy much of WCAG 2.1 AA, which objective O2 is graded against, and
because the project's written specification named it.

### 3. Laravel 13 kept; the proposal's "Laravel 12" is a known discrepancy

The installed framework is Laravel 13 on PHP 8.4. The approved FYP proposal says Laravel 12,
because it was written before Laravel 13 was released.

Laravel 13 was retained. Downgrading would have required re-resolving the dependency tree and
reinstalling Breeze for no technical gain. The discrepancy is recorded in `AGENTS.md` §1 so that
the answer is on record rather than improvised if the panel notices.

### 4. Self-service authentication removed

Breeze ships registration, password reset, email verification and password confirmation.
`AGENTS.md` states that the `users` table exists for administrators only.

All four flows were deleted — routes, controllers, form requests, views and tests — leaving login
and logout. The `password_reset_tokens` table was dropped from the migration and
`email_verified_at` from the `users` table, since neither has a remaining consumer.

The reasoning is not merely tidiness. A public scam-verification tool has no reason to let
strangers create accounts, so registration serves none of O1, O2 or O3 and is scope creep by the
project's own definition. It is also attack surface: password reset in particular is a credential
path that would need hardening in Phase 9 for a feature nobody uses. A parametrised test asserts
that `/register`, `/forgot-password`, `/confirm-password` and `/verify-email` all return 404, so
that re-running a scaffold generator cannot quietly reintroduce them.

### 5. Login is throttled at two layers

`LoginRequest` already rate-limits on an email+IP key. A per-IP `throttle:10,1` was added at the
route as well. These are not redundant: the email+IP limiter stops someone grinding a single
account's password, but an attacker rotating through many usernames from one host never trips
it. The route limiter closes exactly that gap.

### 6. No default administrator credentials

The stock seeder created `test@example.com` with a known password. `AGENTS.md` forbids default
credentials in the seeder, so `AdminUserSeeder` now reads `ADMIN_EMAIL` and `ADMIN_PASSWORD` from
configuration, and when no password is configured it generates a cryptographically random one and
prints it to the console exactly once — never to a file or a log. Re-running the seeder leaves an
existing administrator untouched rather than resetting the password, which would otherwise be a
quiet account-takeover primitive on a shared machine.

### 7. Model weights are gitignored; model metadata is not

The Phase 0 brief said to gitignore `models/`. That was narrowed deliberately.

The `ml-endpoint` skill writes `metrics.json`, `feature_list.json` and the committed split indices
*inside* `models/<name>-v<version>/`, alongside the weights. Those JSON files are the evidence
behind every number that will appear in Chapter 4, and ignoring the directory wholesale would
discard them. The repository therefore ignores weights by extension (`*.bin`, `*.safetensors`,
`*.joblib`, `*.pkl`, `*.pt`, `*.onnx`, `*.h5`) and keeps everything else. Weights are large and
regenerable from `training/train_*.py`; the metrics are neither.

### 8. Python is pinned to 3.11

The machine's default interpreter is 3.14. The virtual environment is built with `py -3.11` and
`pyproject.toml` declares `requires-python = "==3.11.*"`, because the `torch` and `transformers`
wheels the Phase 3 message classifier depends on are not yet published for 3.14.

### 9. `/health` reports null model versions rather than plausible ones

The Phase 0 brief asked for "stub model versions". The endpoint instead returns `null` for both
models and `models_loaded: false`, because no model has been trained yet.

`AGENTS.md` §7 forbids passing a stub off as finished, and a health check that reports a version
string for a model that does not exist is precisely that — it would also defeat the endpoint's
purpose the moment Phase 4 relies on it. A test asserts the nulls, so hardcoding a version to make
the endpoint look complete fails the build.

## Consequences

- The repository now matches its own specification, so the agent skills and phase prompts apply
  without amendment.
- Phase 1 inherits a clean `users` table and must add its own `role` column in a new migration.
- Phase 6 can restyle globally through Sass variables rather than per-template.
- Both placeholder pages state on the page itself that they are not implemented, so no screenshot
  taken during development can be mistaken for a finished feature.

## Deferred: the deployment target

The brief promises a publicly deployed platform, and a PyTorch DistilBERT service needs roughly
1–2 GB of RAM, which most free tiers will not provide. Rather than commit to either a local demo
or a paid VPS before there is anything to measure, the decision is **deliberately deferred to
Phase 9**, and the intervening phases are built so that either outcome remains available:

1. The Flask service stays stateless and configuration-driven, so where it runs is a deployment
   detail rather than an architectural one. Already true as of Phase 0.
2. **Phase 3 must export the fine-tuned model to ONNX with int8 quantisation alongside the
   PyTorch checkpoint, and benchmark both** for memory, latency and F1. Quantisation is expected
   to reduce the memory footprint substantially, plausibly enough to fit a 1 GB instance, and
   tends to improve CPU inference latency — but the actual figures must come from a run, not
   from this paragraph. The measured accuracy cost of quantisation is itself a Chapter 4 result.
3. **Phase 9 adds Docker Compose regardless of the outcome.** It serves the local demo, a VPS
   deployment, and reproducibility on an examiner's machine equally.

The question to put to the supervisor is narrower than "local or deployed": *does a publicly
reachable demo URL satisfy the requirement, or must the system be permanently hosted?* A
Cloudflare Tunnel satisfies the former at no cost while the application runs locally; only the
latter requires a VPS.

## Open question

- **Test database engine.** Tests currently run against in-memory SQLite for speed, while the
  application runs on MySQL. That divergence is harmless in Phase 0 but will need revisiting when
  the Phase 1 schema introduces MySQL-specific behaviour — index length limits under `utf8mb4`,
  and JSON column semantics in particular.
