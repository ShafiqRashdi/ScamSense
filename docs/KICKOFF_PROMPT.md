# Claude Code Prompts — ScamSense

Copy `AGENTS.md`, `SKILLS.md`, and `.claude/` into your empty project folder first, then open
Claude Code there. The prompts below assume those files are present, which is what keeps them
short — the standards live in the repo, not in the prompt.

---

## Prompt 0 — Session opener (paste at the start of every session)

```
Read AGENTS.md and SKILLS.md before doing anything.

This is ScamSense, my Final Year Project: an AI scam verification platform where a
Malaysian user pastes a suspicious SMS/WhatsApp message or URL and gets a risk verdict
with a confidence score and readable indicators, in under 3 seconds.

Stack: Laravel 12 + MySQL + Bootstrap 5 (backend/), Python 3.11 + Flask serving
DistilBERT and Random Forest (ml-service/).

Rules for how we work together:
- Plan before you code. Tell me the files you'll touch and the approach, and wait for my
  go-ahead on anything spanning more than two files.
- Small working increments. The app must run after every change.
- Write the tests with the code, and actually run them before you tell me it's done.
- If something isn't implemented, say so. Never fabricate metrics, citations, or
  test results, and never leave a stub described as finished.
- This is graded work I have to defend to a panel. When you make a design decision,
  explain the trade-off in one or two sentences so I can answer for it.

Tell me which phase of the checklist in AGENTS.md §9 you think we should start with,
and why. Don't write code yet.
```

---

## Phase prompts

Run these one at a time. Don't paste two at once — the value is in reviewing each increment.

### Phase 0 — Scaffold
```
Set up the repo skeleton from AGENTS.md §2.

backend/: fresh Laravel 12, Pint and Pest configured, .env.example with empty keys for
GOOGLE_SAFE_BROWSING_KEY, VIRUSTOTAL_KEY, ML_SERVICE_URL, ML_SERVICE_SECRET.

ml-service/: Flask app factory with blueprints, a /health route returning stub model
versions, pinned requirements.txt, ruff + black + pytest configured.

Root: .gitignore covering .env, vendor/, node_modules/, models/, *.csv over 50MB.
A README with the exact commands to run both services.

Prove it works: both boot, /health returns 200, both test suites run green (even if
nearly empty). Show me the output.
```

### Phase 1 — Data layer
```
Design and implement the MySQL schema. Tables: submissions, verdicts, indicators,
feedback, threat_intel_cache, users (admin only).

Requirements from AGENTS.md §3: submissions store redacted content plus a SHA-256
content_hash for dedup; verdicts store model_version, label, confidence; feedback has a
status column defaulting to 'pending'.

Give me migrations, models with relationships and casts, factories, and a seeder with
realistic Malaysian sample data (Bahasa/English mixed scam messages, phishing URLs
imitating local banks and couriers, plus legitimate examples).

Also implement RedactorService — masks Malaysian phone numbers, IC numbers, emails, and
bank account numbers — with unit tests covering each pattern and a test proving it
doesn't mangle legitimate text.

Then write an ADR explaining the schema decisions.
```

### Phase 2 — URL classifier
```
Work in ml-service/. Implement the lexical URL feature extractor from the ml-endpoint
skill, as pure functions in app/features/url_features.py, each with a unit test using a
malicious and a benign example.

Then training/train_url_model.py: loads a phishing URL dataset, does a stratified
70/15/15 split with the split committed, trains a Random Forest, and writes
models/rf-url-v1.0.0/ with the artefact, metrics.json, and feature_list.json.

Print the confusion matrix and per-class metrics. Report the real numbers. If F1 is
below 0.85 tell me straight, and suggest what to try — don't tune against the test set.
```

### Phase 3 — Message classifier
```
Baseline first: TF-IDF + Logistic Regression on the SMS scam dataset, same split
protocol, metrics recorded.

Then fine-tune distilbert-base-multilingual-cased for binary classification with the
preprocessing rules in the ml-endpoint skill. Save to models/distilbert-msg-v1.0.0/.

Compare both against the frozen test set and tell me honestly whether DistilBERT earns
its cost. Write the comparison up as an ADR either way — a negative result is a real
finding for Chapter 4.
```

### Phase 4 — Prediction API
```
Implement POST /api/v1/predict/message and /api/v1/predict/url in Flask, exactly matching
the contract in AGENTS.md §4. Pydantic validation, 422 on malformed input, HMAC
verification with a 60-second window, models loaded once at startup, indicators populated
from real feature importances and evidence.

Tests: contract shape, HMAC rejection (missing, wrong, stale), 422 cases, and an
inference latency assertion.
```

### Phase 5 — Integration
```
Build the Laravel side: VerifySubmissionRequest, DetectionService, MlServiceClient
(2s timeout, one retry, HMAC signing), and the DTOs.

Critical requirement: when the ML service is unavailable, the user gets a partial verdict
clearly labelled as such — never a 500. Prove it with an Http::fake() feature test.

Also add the ThreatIntel clients for Google Safe Browsing and VirusTotal, with 24-hour
caching keyed on URL hash and graceful degradation when either is unavailable. Never
fetch the submitted URL itself.
```

### Phase 6 — Public interface
```
Build the public UI with Blade + Bootstrap 5, mobile-first. One page: a textarea, a
message/URL toggle, and a result panel showing the verdict, confidence, and indicators
translated into plain Bahasa Malaysia and English.

Non-negotiable: risk level is never conveyed by colour alone (icon + text label too);
submitted URLs are defanged and non-clickable; nothing user-supplied is rendered unescaped.
Design for a 60-year-old on a mid-range Android phone.

Include a short privacy notice on the form saying what is stored and why.
```

### Phase 7 — Feedback loop
```
Implement the community reporting feature for objective O3. Users can agree or dispute a
verdict; submissions land in a moderation queue with status 'pending'; an admin approves
or rejects; approved items export into a versioned retraining dataset.

There must be no code path from user feedback to a live model. Rate-limit and dedupe
feedback per IP. Write the poisoning-resistance rationale into an ADR — I will be asked
about this in the viva.
```

### Phase 8 — Admin dashboard
```
Auth-protected admin area: moderation queue, submission volume over time, verdict
distribution, model performance from metrics.json, and threat-intel cache hit rate.
Explicit role checks on every route, no default credentials in the seeder.
```

### Phase 9 — Hardening and release
```
Run a full security review using the security-review skill. Produce the findings table
with severities, fix everything Critical and High, and add a regression test for each.

Then: run every verification payload from that skill and give me the results as a table I
can paste into Chapter 4. Add a scheduled purge command for the retention policy.
Write the deployment guide and the user manual.
```

---

## Prompts worth reusing

**Before trusting anything:**
```
Review what you just wrote against AGENTS.md and the security-review skill. List
anything that violates the rules, and anything you claimed works but didn't actually run.
```

**When it drifts:**
```
Stop. Re-read AGENTS.md §2 and §3. You've broken [X]. Explain what went wrong and fix it
without changing anything unrelated.
```

**For the report:**
```
Using the fyp-documentation skill, turn the ADRs in docs/adr/ and the metrics in models/
into a draft of Chapter 4. Real numbers only — mark anything unavailable as
[DATA PENDING]. Times New Roman 12pt, double spaced, justified, APA 7.
```

**Before the viva:**
```
You're the FYP panel. Read the codebase and ask me the eight hardest questions you can
about the architecture, the model, and the security posture. Then tell me which of them I
currently can't answer well from what's actually in the repo.
```

---

## Two things to sort out early

**Deployment target.** The brief promises a publicly deployed production platform. A
DistilBERT service needs roughly 1–2 GB RAM, which most free tiers won't give you. Confirm
with your supervisor whether a local demo satisfies the requirement, or budget for a small
VPS. This changes Phase 9 substantially, so ask before you build toward it.

**Dataset.** Start collecting Malaysian scam messages now, in parallel with Phase 0. UCI and
PhishTank are English-heavy and dated; your differentiator over ScamShield is local-language
coverage, and that only exists if you build the corpus. Record the source and consent basis
for every message you collect.
