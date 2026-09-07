# AGENTS.md — ScamSense

Operating instructions for AI coding agents (Claude Code) working in this repository.
Read this file fully before writing any code. If a request conflicts with this file, say so
before proceeding.

---

## 1. Project

**ScamSense** — AI-Based Scam Verification Platform for Online Fraud Prevention.
Final Year Project (FYP4112), Universiti Poli-Tech Malaysia.

A public web application where a non-technical user pastes a suspicious SMS/WhatsApp message
or a suspicious URL and receives, in under 3 seconds, a risk verdict with a confidence score
and human-readable indicators explaining the verdict.

**Client:** Dobi Eta, a laundry business in Bangi, Selangor.
**Users:** Malaysian general public, low digital literacy assumed.

> **Known doc/code discrepancy — Laravel version.** The approved FYP proposal names Laravel 12,
> because it was written before Laravel 13 was released. The implementation runs **Laravel 13**
> on PHP 8.4. This is deliberate, not drift: Laravel 13 was the current stable release when the
> repository was scaffolded, and downgrading would have bought nothing technically while costing
> dependency-resolution work. If the panel raises the mismatch, that is the answer.

### The three graded objectives

| # | Objective | Definition of done |
|---|-----------|--------------------|
| O1 | AI scam detection engine for URLs and messages | Two models served over an internal API; F1 ≥ 0.85 on a held-out test set for both tasks |
| O2 | User-friendly public web interface | Paste → verdict → confidence → indicators, mobile-first, WCAG 2.1 AA, p95 < 3s |
| O3 | Community reporting loop for continuous learning | Feedback captured, moderated, exported to a versioned retraining set |

Every pull request should be traceable to one of O1/O2/O3. If it isn't, it is scope creep —
flag it instead of building it.

---

## 2. Architecture

```
                 ┌──────────────────────────────┐
   Browser  ───► │ Laravel 13  (backend/)       │
                 │  • Blade + Bootstrap 5 UI    │
                 │  • FormRequest validation    │
                 │  • Rate limiting, CSRF       │
                 │  • MySQL persistence         │
                 │  • Threat-intel clients      │
                 └───────┬──────────────┬───────┘
                         │              │
              internal   │              │  HTTPS, keyed, cached
              HTTP+HMAC  │              │
                         ▼              ▼
              ┌──────────────────┐   ┌──────────────────────────┐
              │ Flask ML service │   │ Google Safe Browsing API │
              │  (ml-service/)   │   │ VirusTotal API           │
              │ • DistilBERT     │   └──────────────────────────┘
              │   → message text │
              │ • RandomForest   │
              │   → URL features │
              └──────────────────┘
```

### Hard architectural rules

1. **The Flask service is never exposed to the internet.** It binds to localhost or a private
   network only. All public traffic enters through Laravel.
2. **Laravel never runs Python. Flask never touches MySQL.** The boundary is one JSON contract
   (see §4). Keep it that way — it is the thing the panel will ask about.
3. **ScamSense never visits a submitted URL.** No `file_get_contents`, no cURL to user input,
   no headless browser, no iframe preview, no favicon fetch. URL analysis is lexical
   (structure of the string) plus third-party reputation lookups only. Fetching user-supplied
   URLs from your server is SSRF and it will be the first thing a security-minded panel probes.
4. **Degrade, never fail.** If Flask is down, or VirusTotal rate-limits, the user still gets a
   verdict from whatever signals are available, clearly labelled as partial. Timeouts:
   ML service 2s, external APIs 3s, both with a single retry.

### Repository layout

```
scamsense/
├── AGENTS.md
├── SKILLS.md
├── .claude/skills/          # agent skills, see SKILLS.md
├── docs/                    # ADRs, diagrams, FYP chapter source
├── backend/                 # Laravel 12
│   ├── app/Http/Controllers/
│   ├── app/Http/Requests/
│   ├── app/Services/        # business logic lives HERE
│   ├── app/Services/Detection/
│   ├── app/Services/ThreatIntel/
│   ├── app/DataTransferObjects/
│   ├── app/Models/
│   ├── database/migrations|factories|seeders/
│   ├── resources/views/
│   └── tests/Feature|Unit/
└── ml-service/              # Python 3.11 + Flask
    ├── app/__init__.py      # app factory
    ├── app/routes.py
    ├── app/predictors/      # message_classifier.py, url_classifier.py
    ├── app/features/        # url_features.py, text_preprocess.py
    ├── training/            # notebooks + reproducible train scripts
    ├── models/              # versioned artefacts, gitignored
    └── tests/
```

---

## 3. Non-negotiable security and privacy rules

This is a cybersecurity project. Insecure code is not just a bug here, it is a failed
objective.

- **Treat every submission as hostile.** Validate type and length in a FormRequest before it
  reaches any service. Message: max 5000 chars. URL: max 2048 chars, must parse as
  `http`/`https`, reject other schemes outright.
- **Never render submitted content as HTML.** Blade `{{ }}` only, never `{!! !!}` on user data.
  Defang URLs in the UI (`hxxps://example[.]com`) and never make them clickable.
- **Redact before you store or log.** Malaysian phone numbers, IC numbers, emails, and bank
  account numbers get masked by a `Redactor` service before the submission is persisted or
  written to any log line. Store a SHA-256 `content_hash` for deduplication instead of the raw
  string. **Hash the redacted text, not the raw input** — submissions are short enough that a
  digest of raw PII can be brute-forced back, which would undo the redaction it sits beside.
  Hashing after redaction also collapses one scam template sent with a thousand different
  callback numbers into a single hash, which is what dedup is for. See `docs/adr/0002` §1.
- **No secrets in the repo.** API keys live in `.env`, which is gitignored. `.env.example`
  ships with empty keys. If you ever see a real key in a diff, stop and flag it.
- **Rate limit everything public.** 10 verification requests/minute/IP, 30/hour/IP, enforced
  with Laravel's throttle middleware. Feedback submission: 5/hour/IP.
- **Authenticate the internal hop.** Laravel signs each ML request with an HMAC of the body
  using a shared secret; Flask rejects unsigned or stale (>60s) requests.
- **Community feedback is a data-poisoning vector.** Crowdsourced labels NEVER feed a model
  directly. They land in a `pending` moderation queue, require admin approval, and are only
  exported into a new dataset version that is trained and evaluated deliberately. Write this
  into the code, not just the report — it is a strong panel answer.
- **Parameterised queries only.** Eloquent or bound parameters. No string-concatenated SQL,
  ever, including in seeders and one-off scripts.
- Enable HTTPS-only cookies, `SameSite=Lax`, CSP headers, and `X-Content-Type-Options: nosniff`
  in production config.

---

## 4. The Laravel ⇄ Flask contract

Changing this contract is a breaking change. Update both sides, both test suites, and
`docs/api-contract.md` in the same commit.

**`POST /api/v1/predict/message`**
```json
// request
{ "text": "Tahniah! Anda memenangi...", "request_id": "uuid" }

// response
{
  "request_id": "uuid",
  "label": "scam",              // "scam" | "legitimate"
  "confidence": 0.93,           // 0.0–1.0
  "indicators": [
    { "code": "urgency_language", "weight": 0.31, "evidence": "segera dalam 24 jam" },
    { "code": "prize_claim",      "weight": 0.27, "evidence": "anda memenangi" }
  ],
  "model_version": "distilbert-msg-v1.2.0",
  "inference_ms": 180
}
```

**`POST /api/v1/predict/url`** — same envelope, indicators drawn from lexical features
(`ip_literal_host`, `punycode_host`, `excessive_subdomains`, `suspicious_tld`, `url_shortener`,
`brand_lookalike`, `long_random_path`, `no_https`).

**`GET /health`** — returns loaded model versions. Laravel's health check hits this.

Rules:
- Indicator `code` values are a **closed enum** shared by both services. Laravel maps codes to
  Bahasa Malaysia and English user-facing strings; Flask never sends prose.
- Every response carries `model_version`. Persist it with the verdict so results stay
  reproducible when you retrain — the panel may ask "which model produced this screenshot?"
- Flask returns 422 for malformed input, never a guessed prediction.

---

## 5. Coding standards

### Laravel
- PHP 8.3+ (development runs 8.4), `declare(strict_types=1)` at the top of every class.
- Controllers are thin: validate (FormRequest) → call a service → return a view/JSON resource.
  A controller method over ~20 lines means logic belongs in `app/Services/`.
- Services take and return typed DTOs, not arrays.
- One migration per schema change; never edit a migration that has been committed.
- Follow PSR-12. Run `./vendor/bin/pint` before committing.
- No `env()` outside `config/` files.

### Flask
- Python 3.11, app-factory pattern, blueprints. No logic at import time except model loading.
- Type hints on every public function. `ruff` + `black` clean.
- Pydantic (or marshmallow) schemas validate every request body.
- Models load once at startup into a singleton, never per-request.
- Pin dependencies in `requirements.txt` with exact versions.

### Git
- Conventional commits: `feat(detection): add punycode host indicator`.
- Branch per feature: `feat/url-lexical-features`.
- Never commit: `.env`, `models/*.bin`, `node_modules/`, `vendor/`, datasets over 50 MB.

---

## 6. Testing

Nothing merges without tests. Target: 80% coverage on `app/Services/` and `ml-service/app/`.

| Layer | Tool | Must cover |
|-------|------|-----------|
| Laravel unit | Pest/PHPUnit | Redactor, indicator mapping, DTO construction |
| Laravel feature | Pest/PHPUnit | Full submission flow, validation rejections, rate limiting, ML-service-down fallback (HTTP faked) |
| Flask unit | pytest | Feature extraction, schema validation, HMAC verification |
| Model eval | pytest + sklearn | Accuracy, precision, recall, F1 against a **frozen** held-out set; test fails if F1 < 0.85 |
| Security | manual + tests | XSS payload as message input, `javascript:` URL, oversized body, SQLi string, 11th request in a minute |

Always mock external APIs in tests. A test suite that burns VirusTotal quota is broken.

Keep the held-out test set frozen and never train on it. Report metrics from that set only —
this is the number that goes in Chapter 4.

---

## 7. How to work in this repo

1. **Read before writing.** Inspect existing services and follow their patterns rather than
   inventing a second way to do the same thing.
2. **Plan first for anything non-trivial.** State the files you will touch and the approach,
   then implement.
3. **Small, working increments.** Each change should leave the app runnable.
4. **Run the tests you affect** before reporting done. Don't claim green without running.
5. **Never fabricate.** No invented benchmark numbers, no placeholder citations, no
   `# TODO: real model here` passed off as complete. If something isn't implemented, say so
   plainly.
6. **Log an ADR** in `docs/adr/` for any architectural decision (why DistilBERT over BERT, why
   Random Forest over gradient boosting). These become Chapter 3 content for free.
7. **Ask when the requirement is genuinely ambiguous** — especially anything touching the
   deployment target, which is still unconfirmed with the supervisor.

---

## 8. Commands

```bash
# Laravel
cd backend
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                 # http://127.0.0.1:8000
./vendor/bin/pint && php artisan test

# Flask
cd ml-service
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
flask --app app run --port 5001   # localhost only
ruff check . && black . && pytest
```

---

## 9. Current status

> **Every metric quoted in the report comes from `docs/RESULTS.md`.** That file is the single
> source of truth and is verified against the committed `metrics.json` artefacts. Commit
> messages and superseded ADR revisions may contain figures from earlier configurations; where
> anything disagrees with `docs/RESULTS.md`, that file wins.


- [x] Phase 0 — repo scaffold, both services boot, health check green — see `docs/adr/0001`
- [x] Phase 1 — DB schema, migrations, seeders — see `docs/adr/0002`
- [x] Phase 2 — URL lexical features + Random Forest — see `docs/adr/0003`, `docs/adr/0004`
      ↳ CANONICAL FIGURE: **test F1 0.8984** (phishing class), from
        `models/rf-url-v1.2.0-no-https`, the shipped artefact. Recall 0.9780,
        precision 0.8308, on 44,890 held-out rows, domain-grouped split, 22 features.
        Every other F1 in ADR 0004 belongs to a superseded or comparison artefact.
      ↳ `uses_https` was dropped: all 23,936 Tranco rows had `https://` added during
        dataset assembly, so the feature was partly an artefact of how the CSV was
        built. Ablation showed it was not load-bearing (+0.003 test F1 without it).
      ↳ Quote the domain-grouped figure, never the random-split one. A random split
        scores 0.9606 but leaks 2,979 domains between train and test.
      ↳ The corpus is not Malaysian, and its legitimate half is Tranco-dominated.
        Read ADR 0004 "Limitations" before citing this number in the report.
- [x] Phase 3 — Message classifier — see `docs/adr/0005`, `docs/adr/0009`, `docs/adr/0011`
      ↳ SERVING: `tfidf-msg-v3.0.0-collected-my`, test F1 0.9836 on 1,113 collected
        Malaysian messages (223-row test split). Two corpora were rejected to get
        here: ADR 0005 the synthetic one, ADR 0011 the label-contaminated one.
      ↳ **Do NOT report 0.9735 → 0.9836 as an improvement.** Different test sets,
        106 rows against 223. On the identical 106 rows v3 scores 0.9744 — a delta
        of +0.0009 that hides the real change, which is two fewer missed scams for
        two more false positives. Report the confusion matrix, not the F1.
      ↳ **The collection worked, and it was predicted.** The v2 probe showed recall
        tracking category support; collection was weighted to the three sparse
        categories (parcel 18→111, prize 20→106, impersonation 19→102) and the
        out-of-corpus probe moved 70.8% → 91.7%, missed scams 6/12 → 2/12.
      ↳ **REMAINING LIMIT:** two probe misses persist — a Shopee delivery notice and
        a Petronas prize draw. Both invoke a brand in routine-sounding language
        without urgency or credential vocabulary. Their categories grew six and five
        fold and the misses did not close, so this is phrasing rather than volume.
        More rows in those categories is not the fix.
      ↳ Corpus tooling: `training/validate_corpus.py` then `training/merge_corpus.py`.
        The merge assigns splits BY TEMPLATE, never by row — a group is indivisible,
        which is what keeps templates from straddling a partition (ADR 0005).
      ↳ **DistilBERT has now been measured TWICE and is still not shipped** (ADR 0009
        + its 2026-09-07 amendment). On 1,113 rows: 0.9959 against 0.9836, difference
        +0.0122, 95% CI [-0.0043, +0.0317] — still spans zero. Doubling the corpus
        halved the interval width (0.0727 → 0.0360) without changing the answer.
        Always quote the interval, never the point estimate alone.
      ↳ The two models now MISS THE SAME SINGLE SCAM of 121; the whole difference is
        false positives, 3 against 0. The surviving advantage is in the cheaper error
        under this project's own asymmetry, which weakens the case for adopting it.
      ↳ `training/power_analysis.py` predicted this (~35% odds at 200 test rows) and
        was right. Its 80% target has GROWN to ~1,500 test messages (~7,500 collected)
        because the measured effect shrank. Do not cite the old ~800/~4,000 figures.
      ↳ When DistilBERT is revisited: also export ONNX + int8 quantised and benchmark
        memory/latency/F1 against the PyTorch checkpoint, to keep the 1 GB VPS option
        open (`docs/adr/0001`).
- [x] Phase 4 — Flask prediction API with HMAC — see `docs/api-contract.md`
      ↳ POST /api/v1/predict/url serves rf-url-v2.0.0-pathnorm. Median 30ms, p95 76ms
        over HTTP including HMAC.
      ↳ POST /api/v1/predict/message serves tfidf-msg-v3.0.0-collected-my. It returns
        503 model_unavailable only when an artefact is missing or fails to load, and
        Laravel renders a labelled partial verdict, never a 500.
      ↳ **OPEN DEFECT, `docs/adr/0006`:** the model flags real banking and government
        pages — 40% false positives on a 20-URL real-site probe, including the Maybank
        login page at 0.93. Recall is fine (0 misses on 8 phishing shapes). Cause is
        corpus prior, not code. Do NOT retune the threshold against that probe.
- [x] Phase 5 — Laravel integration, fusion, threat intel — see `docs/adr/0007`
      ↳ DetectionService implements the ADR 0007 fusion order. Clean threat intel
        NEVER lowers a verdict — a clean lookup is absence of evidence, and would
        de-escalate most reliably on freshly registered phishing domains.
      ↳ Verified-domain gate caps genuine institutional URLs at Low, recorded as
        an indicator with source `registry`. A SAFETY MITIGATION, never model
        accuracy: every figure in docs/RESULTS.md is measured with it disabled.
      ↳ ML service unreachable, 503, or malformed → partial verdict, never a 500.
- [x] Phase 6 — Public UI (submit, verdict, indicators) — bilingual, three risk bands
      — visual design rationale in `docs/adr/0012`
      ↳ Verified-address panel names the institution; the "we checked the address,
        not the page" scoping stays visible and is asserted outside <details>.
      ↳ **The brand green is load-bearing, not decorative.** It is deliberately far
        from the red-amber risk hues so nothing in ordinary page furniture competes
        with a risk signal. Do not introduce a warm accent (ADR 0012).
      ↳ **OPEN A11Y DEFECT, ADR 0012:** `$sc-line-strong #d8cbb9` is the input border
        and reaches only 1.57:1 against the input background, against WCAG 2.1
        SC 1.4.11's required 3.0:1. Candidate `#b9a68d` measures 3.07:1, unevaluated
        in situ. Do NOT close this by lowering `$min-contrast-ratio`.
      ↳ Any palette change must re-run the contrast table in ADR 0012. Those figures
        were computed, not estimated; breaking one is a regression, not a restyle.
      ↳ Malay reviewed by Shafiq: `pelik`, `tidak pasti`, `alamat` throughout.
      ↳ Risk carried by icon + text + colour; icons are inline SVG so a failed
        webfont cannot reduce the verdict to colour alone.
- [x] Phase 7 — Feedback loop + moderation queue — see `docs/adr/0008`
      ↳ The gate is a MODEL INVARIANT, not a service convention: TrainingSample
        refuses to create a community row without approved feedback and a named
        approver. Tests attack it via direct create, mass assignment, an origin
        flip after insert, and a seeder-style insert, and assert no bulk-approve
        route exists.
      ↳ Three steps: approve → export to a dataset version → train. Never collapsed.
      ↳ The MODERATOR supplies the label, not the reporter. No default selection.
      ↳ Provenance survives: feedback_id, submission_id, origin, approved_by.
- [x] Phase 8 — Admin dashboard + metrics
      ↳ Submission volume (zero-filled), verdict distribution, indicator frequency
        split by source, degraded-verdict rate and reasons, threat-intel cache hit
        rate, retraining set split by provenance, verdicts by model version.
      ↳ Model performance is READ from the committed metrics.json artefacts, never
        recomputed, so a dashboard figure and a Chapter 4 figure cannot drift.
        The REPORTING_QUALIFIER travels with each metric into the UI.
      ↳ `auth` plus an explicit role check on the whole admin group. A Viewer role
        exists so the check is testable rather than vacuous.
- [x] Phase 9 — Hardening, security review, deployment guide, user manual
      ↳ Security review found 10 issues (5 High); all fixed with regression tests.
        See `docs/security-review-2026-08.md`.
      ↳ Findings 1 and 2 are Chapter 4 material: redaction was applied thoroughly
        inbound, but a submitted URL re-entering through an OUTBOUND API call
        reached the log via the HTTP client's exception message. Neither was
        visible by reading the code.
      ↳ Deployment is ALL-RAILWAY. The ML service stays on the private network
        and MUST NOT be given a public domain — that single click breaks
        AGENTS.md §2 rule 1. See `docs/deployment.md`.
      ↳ `docs/user-manual.md` covers the public interface and the admin area,
        English and Bahasa Malaysia.
      ↳ Docker Compose regardless of the hosting outcome: it serves the local demo, a VPS
        deploy, and reproducibility on an examiner's machine equally.
      ↳ Deployment target is still open. Ask the supervisor the narrow question: does a
        publicly reachable demo URL suffice, or must it be permanently hosted?

Agents: update this checklist as phases complete.
