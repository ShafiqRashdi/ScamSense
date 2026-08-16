# Security review — Phase 9

- **Date:** 2026-08-12
- **Scope:** whole application, ahead of a live cloud deployment with a public URL
- **Method:** the checklist in `.claude/skills/security-review`, plus the eight verification
  payloads, plus targeted empirical probes where a claim needed evidence rather than reading
- **Status:** findings only. Nothing in this pass has been fixed.

---

## Summary

| # | Severity | Finding | Fixed |
|---|---|---|---|
| 1 | **High** | Google Safe Browsing API key is written to the log on any connection failure | ☐ |
| 2 | **High** | Submitted URL reaches the log through the VirusTotal exception path | ☐ |
| 3 | **High** | No security headers — no CSP, nosniff, HSTS, frame options | ☐ |
| 4 | **High** | The chosen deployment topology puts the ML service on the public internet | ☐ |
| 5 | **High** | No retention period and no purge command, despite the privacy notice | ☐ |
| 6 | Medium | `.env.example` ships production-unsafe defaults | ☐ |
| 7 | Medium | Application database user is `root` | ☐ |
| 8 | Medium | HMAC window bounds replay but does not prevent it | ☐ |
| 9 | Low | One `{!! !!}` in a view, currently safe but unpinned | ☐ |
| 10 | Low | Generated admin password is printed to stdout | ☐ |

Findings 1, 2 and 4 were not visible by reading; they needed a probe. Recorded because the same
is likely true of the next one.

## What passed

Verified, not assumed:

- **No secrets in the repository.** Checked with `git ls-files`, not `.gitignore`: no `.env`
  tracked, no file matching a Google or 64-hex key shape, and the live database password does
  not appear in any tracked file.
- **No SSRF.** No `file_get_contents`, `curl_exec`, `fopen` or `Http::` call outside the two
  threat-intel clients, and both send the submitted URL to a *fixed* third-party endpoint as a
  parameter. `ml-service/app` imports no networking library at all, and a test enforces that by
  parsing the module's own imports.
- **No injection.** Every `selectRaw` is a static string with no interpolation. No `exec`,
  `shell_exec` or `subprocess` anywhere in application code.
- **The moderation gate is a model invariant**, on both `creating` and `updating`, so a seeder or
  a bulk action cannot route around it.
- **Session is regenerated on login**, so fixation is handled.
- **Flask binds to `127.0.0.1`** in `wsgi.py` — see finding 4 for why that is about to become a
  problem rather than a reassurance.
- **Nothing that should not ship is tracked**: no corpus CSV, no model weights. The committed
  `metrics.json` files contain no URLs or message text, and `split_indices.json` is row integers.

### Verification payloads — all pass

Now in `tests/Feature/SecurityPayloadTest.php` rather than a one-off script, so a regression is
caught by the suite.

| Payload | Expected | Result |
|---|---|---|
| `<script>alert(1)</script>` | rendered escaped | ✅ escaped |
| `<img src=x onerror=alert(1)>` | rendered escaped | ✅ escaped |
| `javascript:alert(document.domain)` | rejected at validation | ✅ 422 |
| `http://127.0.0.1:5001/api/v1/predict` | analysed as text, never fetched | ✅ not fetched |
| `http://169.254.169.254/latest/meta-data/` | analysed as text, never fetched | ✅ not fetched |
| `' OR '1'='1` | stored as literal text | ✅ literal |
| 6,000 characters | rejected | ✅ 422 |
| 11 requests in 60s | throttled | ✅ 429 |
| Submitted URL in the page | defanged, not clickable | ✅ `hxxp://…[.]xyz` |

---

## Finding 1 — Google Safe Browsing API key is written to the log

**Severity: High** · `backend/app/Services/ThreatIntel/GoogleSafeBrowsingClient.php:67,71`

The key is passed in the query string, as that API requires:

```php
->post(self::ENDPOINT.'?key='.urlencode($key), [...]);
```

On failure the exception message is logged:

```php
Log::warning('Safe Browsing unreachable.', ['reason' => $exception->getMessage()]);
```

Guzzle's `ConnectionException` message embeds the full request URL. Verified directly:

```
cURL error 28: Connection timed out after 1003 milliseconds … for
http://127.0.0.1:9/v4/threatMatches:find?key=AIzaFAKEKEY123
```

**Why it matters.** Every timeout or DNS failure writes the live API key into
`storage/logs/laravel.log`. On the chosen platform, logs are collected by the provider and
retained outside the application, so the key spreads further than the container. A timeout is
not an unusual event — it is the normal failure mode this code was written to handle, so the
leak fires on exactly the path that was supposed to be safe.

**Fix.** Log a redacted reason. Extract the cURL error number or a short classification rather
than the raw message, or strip anything matching `key=[^&]*` before logging. A regression test
should assert that no configured secret appears in the log after a simulated failure.

## Finding 2 — Submitted URL reaches the log through VirusTotal

**Severity: High** · `backend/app/Services/ThreatIntel/VirusTotalClient.php:63,67`

VirusTotal identifies a URL by its base64url encoding, placed in the request path:

```php
->get(self::ENDPOINT.$this->urlId($url));
```

The same exception-message logging applies, so the encoded URL lands in the log. The encoding is
not protection — verified round-trip:

```
path id:   aHR0cHM6Ly92aWN0aW0tZXhhbXBsZS5jb20vc2VjcmV0LXBhdGg_dG9rZW49YWJj
decodes to https://victim-example.com/secret-path?token=abc
```

**Why it matters.** AGENTS.md §3 requires redaction before *any* log write, and this bypasses it
entirely — the URL never passes through `RedactorService` on this path. Submitted URLs routinely
carry session tokens, reset links and identifiers belonging to the person who was targeted, which
makes this a PDPA 2010 exposure and not only a hygiene problem. It is also the one finding whose
blast radius grows with adoption.

**Fix.** As finding 1: log a classification, not the message. The provider name and an error
category are all the log needs.

## Finding 3 — No security headers

**Severity: High** · no middleware exists

Nothing sets `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`,
`Strict-Transport-Security` or `X-Frame-Options`. The public layout sets
`<meta name="referrer" content="no-referrer">`, which covers one of these partially and only for
documents that parse far enough to reach it.

**Why it matters.** The application deliberately renders attacker-authored strings. Escaping is
correct today — the payload tests confirm it — but CSP is the control that limits the damage when
an escaping bug eventually appears, and this codebase will keep growing. `nosniff` matters
because submitted content is echoed back to the browser. `X-Frame-Options` matters because a
verdict page framed inside an attacker's site is a convincing way to fake a "safe" result.

Now that the target is a public URL, HSTS becomes relevant too.

**Fix.** A `SecurityHeaders` middleware on the web group. The CSP can be strict — the app loads
no third-party scripts, styles or fonts, and Vite output is same-origin — so
`default-src 'self'` is achievable rather than aspirational.

## Finding 4 — The deployment topology puts the ML service on the public internet

**Severity: High** · architectural, `docs/adr/0001`, AGENTS.md §2 rule 1

AGENTS.md rule 1 is unambiguous: *"The Flask service is never exposed to the internet. It binds
to localhost or a private network only. All public traffic enters through Laravel."*

Hugging Face Spaces serves applications on a public hostname, and the platform requires the
process to bind a public interface rather than loopback. `wsgi.py` currently binds `127.0.0.1`,
so deploying it there **requires changing that line** — and that change is the tell that the
architectural rule is being broken rather than satisfied.

**Why it matters.** The rule exists because an internet-reachable prediction endpoint is
directly attackable: unmetered inference is a denial-of-wallet and denial-of-service vector, and
it removes the layer where rate limiting, validation and redaction live. The panel will have read
rule 1, because it is stated as one of four hard architectural rules.

**What is genuinely in place.** HMAC verification on every prediction route, rejecting unsigned
and stale requests, with the failure detail deliberately coarse. That is real defence and it was
built for exactly this reason. It is a mitigation, not a substitute for the rule.

**Fix — a decision, not a patch.** Either:

- **(a)** Keep the rule and change the topology: run Flask as a private service alongside Laravel
  on Railway, reachable only on the internal network. This preserves the architecture exactly as
  documented and as defended in ADR 0001.
- **(b)** Keep Hugging Face Spaces and amend the rule in AGENTS.md and ADR 0001 explicitly,
  stating that the ML service is internet-reachable, that HMAC plus rate limiting is the
  compensating control, and why that trade was accepted.

**(a) is preferable** and is what I would argue for. Option (b) is defensible if argued
explicitly, but silently violating a rule the report states as architectural is not — that is a
much worse viva moment than an amended rule with a reason attached.

## Finding 5 — No retention period, no purge

**Severity: High** · nothing in `app/Console/Commands`, nothing in `routes/console.php`

The submission form tells users *"We keep the message so we can improve our scam detection."*
There is no stated period and no mechanism that ever deletes anything.

**Why it matters.** PDPA 2010 requires personal data not be kept longer than necessary. The
promise on the form creates an expectation the system does not meet, and submissions contain
third parties' data by design. Redaction reduces this exposure but does not eliminate it — free
text is unbounded and the redactor is pattern-based.

**Fix.** A `scamsense:purge` command deleting submissions older than a documented period,
cascading through verdicts, indicators and feedback (the foreign keys already cascade). Scheduled
in `routes/console.php`, not merely available — the deployment guide should show it running.
Approved training samples should be exempt, since those were promoted deliberately.

## Finding 6 — `.env.example` ships production-unsafe defaults

**Severity: Medium** · `backend/.env.example:2,4,37`

```
APP_ENV=local
APP_DEBUG=true
SESSION_SECURE_COOKIE=false
```

Copied to production unchanged — which is what a deployment guide's "copy .env.example" step
invites — this leaks stack traces containing file paths, configuration and query fragments to
anyone who triggers an error, and sends the session cookie over plain HTTP.

**Fix.** The deployment guide must set these explicitly, and it would be better for the platform
environment variables to be the source rather than a copied file.

## Finding 7 — Application database user is `root`

**Severity: Medium** · `backend/.env`

`DB_USERNAME=root`. Acceptable locally; not for a deployment. A SQL injection or an
application-level compromise inherits full server privileges, including `DROP DATABASE` and
access to every other schema on the instance.

**Fix.** A dedicated user with `SELECT, INSERT, UPDATE, DELETE` on `scamsense.*` only. No
`DROP`, no `GRANT`, no access to other schemas. Migrations can run under a separate,
higher-privileged user when needed, which is a defensible pattern to describe in the viva.

## Finding 8 — HMAC window bounds replay but does not prevent it

**Severity: Medium** · `ml-service/app/security.py:37,99`

A captured signed request can be replayed within the 60-second window. This is documented as an
accepted limitation in the module docstring, and the reasoning holds: prediction requests have no
side effects, so a replay produces a duplicate prediction and nothing more.

Recorded here because finding 4 changes the exposure. On loopback the attacker must already be on
the host; on a public endpoint, anyone able to observe or induce a request has a 60-second window.

**Fix.** If option (a) in finding 4 is taken, no change is needed and the docstring already
explains why. If (b) is taken, add a nonce cache — a `request_id` seen twice inside the window is
rejected, and `request_id` is already in the contract.

## Finding 9 — One `{!! !!}` in a view

**Severity: Low** · `backend/resources/views/verify/result.blade.php:25`

```blade
<span aria-hidden="true">{!! $risk->iconSvg() !!}</span>
```

The value comes from `RiskLevel::iconSvg()`, a four-case enum returning hardcoded SVG. No user
input can reach it, so this is not currently a vulnerability.

It is a finding because the review rule is "grep for `{!! !!}`, there should be none", and an
exception that is safe only by argument needs the argument written down next to it — otherwise
the next person greps, finds a precedent, and adds a second one that is not safe.

**Fix.** A comment stating why it is safe, and a test asserting the rendered SVG is unaffected by
submitted content.

## Finding 10 — Generated admin password is printed to stdout

**Severity: Low** · `backend/database/seeders/AdminUserSeeder.php:52`

Printing once is the right design for local use, and it is far better than a fixed default. On a
platform, seeder output is captured into build or deploy logs that persist and may be visible to
anyone with project access.

**Fix.** The deployment guide should set `ADMIN_PASSWORD` from a platform secret rather than
relying on the printed value, and should rotate it after first login.

---

## Not findings, but worth stating

- **`ADMIN_PASSWORD=1234` was present in the local `.env` during development** and has since been
  changed. It was never committed — `git ls-files` confirms no `.env` is tracked — so no rotation
  beyond the change already made is required.
- **The 60-second HMAC window and the verified-domain gate** are both documented trade-offs with
  reasoning recorded in ADRs. Neither is an oversight, and both should be presented as decisions.
