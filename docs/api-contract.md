# Laravel ⇄ Flask API contract

The single JSON boundary between the two services (AGENTS.md §2, rule 2).

**Changing anything here is a breaking change.** Update both sides, both test suites and this
document in the same commit.

The Flask service binds to loopback and is never exposed to the internet. All public traffic
enters through Laravel.

- Base URL: `http://127.0.0.1:5001` (configured as `ML_SERVICE_URL`)
- Schemas: `ml-service/app/schemas.py` — those Pydantic types *are* this contract
- Tests: `ml-service/tests/test_predict_routes.py`, `test_security.py`

---

## Authentication

Every prediction route requires an HMAC signature. `/health` does not — Laravel's health check
needs it and it exposes only version strings.

| Header | Value |
|---|---|
| `X-ScamSense-Timestamp` | Unix timestamp, seconds, as a string |
| `X-ScamSense-Signature` | `hex(hmac_sha256(secret, timestamp + "." + rawBody))` |

The secret is `ML_SERVICE_SECRET`, byte-identical in `backend/.env` and `ml-service/.env`.

Two details that matter:

- **The signature covers the timestamp and body together**, not the body alone. Signing only the
  body would let a captured request be replayed forever with a fresh timestamp.
- **Sign the exact bytes sent.** Re-serialising the payload can change key order and produce a
  signature that will not verify.

Requests older or newer than **60 seconds** are rejected. The future bound matters: a clock far
ahead would otherwise widen the replay window indefinitely.

This is defence in depth, not the only control — but loopback is not a trust boundary on a
shared host, so it stands between any local process and the prediction routes.

### PHP reference

```php
$body      = json_encode($payload);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, config('services.ml_service.secret'));

Http::withHeaders([
        'X-ScamSense-Timestamp' => $timestamp,
        'X-ScamSense-Signature' => $signature,
        'Content-Type'          => 'application/json',
    ])
    ->timeout(config('services.ml_service.timeout'))
    ->retry(config('services.ml_service.retries'), 200)
    ->withBody($body, 'application/json')   // send the bytes that were signed
    ->post(config('services.ml_service.url').'/api/v1/predict/url');
```

---

## `GET /health`

Unauthenticated. Reports what is **actually** loaded — a model with no artefact reads `null`
rather than a plausible version string.

```json
{
  "status": "ok",
  "service": "scamsense-ml",
  "version": "0.2.0",
  "models": { "message": null, "url": "rf-url-v1.2.0-no-https" },
  "models_loaded": false
}
```

`models_loaded` is `false` while any model is absent. It is currently `false` and correctly so:
there is no message model (ADR 0005).

---

## `POST /api/v1/predict/url`

### Request

```json
{ "url": "http://maybank2u.secure-verify.xyz/login", "request_id": "uuid" }
```

| Field | Rules |
|---|---|
| `url` | required, 1–2048 chars, scheme must be `http` or `https` |
| `request_id` | required, 1–64 chars, echoed back for correlation |

Unknown fields are rejected. Laravel validates first; this service re-checks because it must not
assume its caller validated correctly.

### Response — 200

```json
{
  "request_id": "uuid",
  "label": "scam",
  "confidence": 0.94,
  "indicators": [
    { "code": "brand_lookalike", "weight": 0.0313, "evidence": "secure-verify.xyz", "source": "model" },
    { "code": "suspicious_tld",  "weight": 0.0316, "evidence": ".xyz",              "source": "model" },
    { "code": "no_https",        "weight": 0.0,    "evidence": "http",              "source": "pattern" }
  ],
  "model_version": "rf-url-v1.2.0-no-https",
  "inference_ms": 12
}
```

| Field | Notes |
|---|---|
| `label` | `"scam"` or `"legitimate"` |
| `confidence` | 0.0–1.0, **for the label returned**. A legitimate verdict at probability 0.02 reports 0.98, because a user reads the raw number as the opposite |
| `indicators` | At most 5, strongest first |
| `model_version` | Persist with the verdict so a Chapter 4 screenshot is traceable |
| `inference_ms` | Model time only, excluding transport |

---

## `POST /api/v1/predict/message`

### Request

```json
{ "text": "Tahniah! Anda memenangi...", "request_id": "uuid" }
```

`text` is required, 1–5000 chars. `request_id` as above.

### Current behaviour — 503

**There is no message model.** The available corpus is 1,732 rows from 56 templates and cannot
support a valid evaluation, so no artefact was produced rather than one that would look finished
(ADR 0005).

```json
{
  "error": "model_unavailable",
  "detail": "The message model is not loaded. No artefact exists...",
  "request_id": "uuid"
}
```

Laravel must turn this into a **partial verdict clearly labelled as such** — never a 500, and
never a confident answer (AGENTS.md §2, rule 4). The `verdicts` table already carries
`is_partial` and `partial_reason` for exactly this.

Validation still runs first, so a malformed body gets a 422 whether or not the model exists.

When a model does exist this route returns the same 200 envelope as `/predict/url`.

---

## Indicator codes

A **closed enum shared by both services**. Flask sends codes; Laravel maps them to Bahasa
Malaysia and English through `lang/ms/indicators.php` and `lang/en/indicators.php`. Flask never
sends prose.

Adding a code without adding both translations leaves a user staring at a raw identifier.

| Code | Meaning |
|---|---|
| `ip_literal_host` | Host is a bare IP address |
| `punycode_host` | Internationalised domain, used for homograph attacks |
| `brand_lookalike` | Invokes a Malaysian brand on a domain that brand does not operate |
| `credential_trick_at_symbol` | `@` in the authority — the real host is after it |
| `url_shortener` | Destination is hidden behind a shortener |
| `suspicious_tld` | TLD with a known abuse skew |
| `excessive_subdomains` | Stacked labels imitating a legitimate hierarchy |
| `non_standard_port` | Explicit non-standard port |
| `sensitive_keyword_path` | Credential-harvesting vocabulary, English or Malay |
| `long_random_path` | Long generated token, typical of one-off phishing pages |
| `high_entropy_host` | Random-looking host |
| `no_https` | Plain HTTP — **`source: "pattern"`, weight 0** |
| `urgency_language`, `prize_claim`, `credential_request`, `payment_demand` | Message indicators, not yet emitted |

### `source` is not decoration

| Value | Meaning |
|---|---|
| `model` | A real feature attribution — `weight` is the model's learned importance |
| `pattern` | A curated rule the model never trained on — `weight` is always 0 |
| `threat_intel` | A third-party reputation hit (Phase 5) |

`no_https` is `pattern` because `uses_https` was **ablated out of training** — 16% of the
corpus's legitimate rows had `https://` written in during assembly, so the feature partly
measured how the CSV was built (ADR 0004). A plain-http login page is still worth showing a
human, but claiming a model importance for a feature the model never saw would be precisely the
dishonesty this field exists to prevent.

`weight` means "how much does this signal matter to the model in general", **not** "how certain
are we about this URL". Do not render it as confidence.

---

## Status codes

| Code | Meaning | What Laravel should do |
|---|---|---|
| 200 | Prediction returned | Persist the verdict and render it |
| 401 | Signature missing, wrong, or stale | Log and alert — this is a bug or an intrusion, not a user error |
| 422 | Request failed validation | Log; the FormRequest should have caught it first |
| 503 | Model not loaded, or service misconfigured | **Partial verdict, clearly labelled.** Never a 500 |

Failure details are deliberately coarse. Distinguishing "signature mismatch" from "timestamp
expired" tells an attacker which half of the check to work on, and returning the full Pydantic
error structure hands over a schema map.

---

## Timeouts

From AGENTS.md §2, rule 4, configured in `config/services.php`:

| Hop | Timeout | Retries |
|---|---|---|
| Laravel → Flask | 2s | 1, after 200ms |
| Laravel → Safe Browsing / VirusTotal | 3s | 1 |

A timeout is a partial verdict, not an error page.
