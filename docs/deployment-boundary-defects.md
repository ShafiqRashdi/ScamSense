# Defects the test suite could not see

**Recorded 2026-08-24. Chapter 5 material.**

Four defects were found during Phase 9 and after. Each made the running system produce wrong
output for a user. **None of them failed a single test**, and at the moment each was live the
suite was fully green — 311 Laravel tests and 204 Python tests passing.

They share a shape worth stating once rather than four times:

> A test suite verifies the code. These defects were in the space **between** the code and the
> environment it runs in — how a process is launched, what a long-running server has cached,
> what a seeder refuses to do. Nothing in that space is reachable from a test that constructs
> its own application instance, and a suite that passes says nothing about it.

That is the finding. The four cases below are the evidence for it.

---

## 1. The shared secret arrived with a trailing space

**Symptom.** Every check on the site answered *"We could not check this properly."* Both
services running, both models loaded.

**Cause.** `start.bat` launched the ML service with:

```bat
cmd /k "... && set ML_SERVICE_SECRET=%ML_SECRET% && python wsgi.py"
```

Batch assigns everything up to the `&&` — **including the space before it**. The process came
up holding a 65-character secret while Laravel signed with the 64-character one, so every
signed request failed the HMAC check with a 401.

**Why it stayed hidden.** `/health` reported `status: ok` and `models_loaded: true` throughout,
and both were true. It is unauthenticated by design, so it never exercises the signature at
all. Laravel then degraded correctly to a partial verdict, exactly as specified — so the
system's own error handling converted a hard failure into a soft, plausible-looking one. It was
diagnosed and "fixed" twice by restarting the process before anyone compared the two values
byte for byte.

**Fix.** `set "VAR=value" && ...` puts the space outside the assignment. `create_app` also
strips the secret after the config merge, so the whole class of launcher quoting bug is
harmless rather than fixed once.

**Where:** `start.bat`, `ml-service/app/__init__.py`, `ml-service/tests/test_security.py`,
commit `df02862`.

---

## 2. A stale process held the port

**Symptom.** Identical to defect 1 — every verdict degraded.

**Cause.** A Flask process from an earlier session was still listening on 5001 with an
out-of-date secret. A newly launched service could not bind and exited; the old one kept
answering.

**Why it stayed hidden.** Same mechanism. `/health` answers from whichever process holds the
port, and it answered healthily.

**Fix.** `start.bat` now refuses to launch when 5001 or 8000 is occupied, naming the likely
cause, rather than starting a service that silently loses the race.

**Where:** `start.bat`.

---

## 3. The dev server served a deleted stylesheet

**Symptom.** CSS changes had no effect. The page kept rendering an older build.

**Cause.** `php artisan serve` caches Vite's manifest in memory for the life of the process.
After `npm run build` the manifest on disk pointed at the new hashed filename while the running
server kept emitting the old one — a file that had already been deleted from
`public/build/assets`.

**Why it stayed hidden.** No test renders through a long-running server; feature tests build a
fresh application per test and read the manifest each time. `php artisan optimize:clear` does
not fix it either, which makes it doubly confusing: the obvious remedy appears to fail.

**Fix.** Documented rather than coded — restart `artisan serve` after `npm run build`. Noted in
the deployment guide, because the same class of staleness applies to any long-lived PHP process
in production.

**Where:** `docs/deployment.md`.

---

## 4. Changing `ADMIN_PASSWORD` did nothing

**Symptom.** The configured password did not work. Neither did the previous one.

**Cause.** `AdminUserSeeder` only ever *creates*. Against an existing account it prints
`already exists — left unchanged` and returns — correct behaviour, since a seeder silently
resetting a live password would be worse than the problem it solves. But there was no other
path: no change screen, no reset flow, no command. The environment value and the stored hash
diverged permanently.

**Why it stayed hidden.** The seeder behaves exactly as its tests require. The defect is the
*absence* of a second path, and absent code has no test. Worse, `docs/deployment.md` instructed
the operator to "rotate after first login" — a documented control that nothing implemented.

**Fix.** `scamsense:admin-password`, with the guide corrected to explain that the seeder never
modifies an existing account.

**Where:** `backend/app/Console/Commands/SetAdminPassword.php`,
`docs/security-review-2026-08.md` (finding 10 follow-up), `docs/deployment.md`.

---

## What was done about the class, not the instances

Fixing four bugs is not a finding. The response that generalises is
**`php artisan scamsense:doctor`**, which sends a real signed request for both a link and a
message and reports what came back.

It exists because of what defects 1 and 2 demonstrated: **the only check that can detect a
broken hop is one that performs the hop.** `/health` cannot, and no amount of it answering
correctly means anything, because it exercises a different path from the one that matters. The
doctor was verified against a deliberately mis-secreted service — `/health` reported healthy,
the doctor reported the failure and named the cause.

Two lessons for Chapter 5, stated as findings rather than apologies:

**Graceful degradation hides its own trigger.** AGENTS.md §2 rule 4 requires that a dependency
failure produce a labelled partial verdict rather than an error, and that requirement is
correct — a user gets an honest incomplete answer instead of a stack trace. But it converts a
loud failure into a quiet one, and the operator is a user too. The dashboard's degraded-verdict
rate exists for exactly this reason, and on reflection it was not enough on its own: a rate
tells you something is wrong, not what.

**A documented control that nothing implements is not a control.** Defect 4's real failure was
not the seeder; it was a sentence in the deployment guide describing a capability that had
never existed. This pairs with security review findings 1 and 2, where redaction was correctly
applied at the inbound boundary and absent at an outbound one — the same shape, a control
believed to be in place because it was written down.

---

## Citing this

| Claim | Cite |
|---|---|
| Four defects invisible to a green suite | This file |
| The HMAC quoting bug, with the byte-level confirmation | This file §1; commit `df02862` |
| `/health` cannot detect a secret mismatch | This file §1; `Doctor.php` docblock |
| The doctor command and its verification | `backend/app/Console/Commands/Doctor.php`; `tests/Feature/DoctorCommandTest.php` |
| A documented control that nothing implements | This file §4; `docs/security-review-2026-08.md` finding 10 follow-up |
| Graceful degradation hiding its trigger | This file, closing section |
