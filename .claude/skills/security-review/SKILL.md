---
name: security-review
description: Use before merging any change that touches user input, authentication, file handling, external API calls, database writes, logging, or deployment configuration. Also use when the user asks for a security review, hardening pass, or OWASP check of ScamSense.
---

# Security Review

A scam-detection platform that is itself insecure is a contradiction the panel will enjoy
pointing out. Run this pass deliberately; don't skim it.

Output a findings list with severity (Critical / High / Medium / Low), file and line, why it
matters, and a concrete fix. No finding is closed until there is a test proving it.

## Threat model specific to ScamSense

The application deliberately accepts attacker-controlled strings — that is the product. Three
consequences drive everything below:

1. Submitted content will contain XSS payloads, because people paste real scam messages.
2. Submitted URLs are hostile by definition; anything that dereferences them is SSRF.
3. Submissions contain third parties' personal data (the scammer's target's name, phone,
   account numbers), so this is a PDPA 2010 concern, not just a security one.

## Checklist

### Input
- [ ] Every entry point has a FormRequest / Pydantic schema. No raw `$request->input()` use.
- [ ] Length caps enforced server-side (message 5000, URL 2048), not just in HTML `maxlength`.
- [ ] URL scheme allowlisted to `http`/`https` via `parse_url()`, not regex.
- [ ] Content-Type enforced on API routes; oversized bodies rejected at the web-server layer.

### Output
- [ ] No `{!! !!}` on user data anywhere. Grep for it.
- [ ] Submitted URLs are defanged and never appear in `href`, `src`, `action`, or inline styles.
- [ ] No user data interpolated into inline `<script>` blocks or JS string literals.
- [ ] CSP header set; `X-Content-Type-Options: nosniff`; `Referrer-Policy: no-referrer`.

### SSRF — the one most likely to be missed
- [ ] Grep for `file_get_contents`, `curl_`, `Http::get`, `requests.get`, `urlopen`, `fetch`
      and confirm **none** of them receive a user-supplied URL.
- [ ] Submitted URLs go to third-party reputation APIs as a *parameter*, never as a target.
- [ ] No link preview, no favicon fetch, no screenshot service, no `<iframe>`, no
      `<link rel=prefetch>` on submitted domains.

### Injection
- [ ] Eloquent or bound parameters only; grep for `DB::raw` and `whereRaw` with variables.
- [ ] No `exec`, `shell_exec`, `system`, `subprocess` with any user-derived string.
- [ ] Pickle/joblib deserialisation only from files the project itself produced, never from
      user upload.

### Secrets and config
- [ ] `.env` gitignored; `.env.example` has empty values; no keys in code, tests, or fixtures.
- [ ] `APP_DEBUG=false` in production; stack traces never shown to users.
- [ ] Flask bound to `127.0.0.1` or a private interface, never `0.0.0.0` on a public host.
- [ ] HMAC shared secret is ≥32 random bytes and differs per environment.

### Abuse and availability
- [ ] Throttle middleware on every public route; verify with a test hitting the limit.
- [ ] Timeouts on all outbound calls; no unbounded retries.
- [ ] Third-party API responses cached so a demo can't exhaust the free tier.
- [ ] ML service failure returns a degraded verdict, not a 500.

### Privacy / PDPA
- [ ] Redaction runs before persistence and before any log write.
- [ ] Log lines contain the request ID and the verdict, never the submitted content.
- [ ] A documented retention period with a scheduled purge command.
- [ ] Privacy notice on the submission form stating what is stored and why.

### Integrity of the learning loop
- [ ] Feedback writes to a `pending` moderation queue; no path from user feedback to a live
      model without human approval.
- [ ] Feedback rate-limited and deduplicated per IP to blunt mass mislabelling.
- [ ] Retraining is a manual, versioned, evaluated action.

### Auth (admin dashboard)
- [ ] Admin routes behind auth middleware plus an explicit role check on every route.
- [ ] No default credentials in seeders for anything that could reach production.
- [ ] Session fixation handled (Laravel regenerates on login — verify it isn't bypassed).

## Verification payloads to test with

```
<script>alert(1)</script>              → must render escaped, never execute
<img src=x onerror=alert(1)>           → must render escaped
javascript:alert(document.domain)      → must be rejected at validation
http://127.0.0.1:5001/api/v1/predict   → accepted as text, never fetched
http://169.254.169.254/latest/meta-data/ → accepted as text, never fetched
' OR '1'='1                            → stored as literal text
A × 6000 chars                         → rejected with 422
11 requests in 60s                     → 429 on the 11th
```

Each of these belongs in the test suite, and the results table belongs in Chapter 4.
