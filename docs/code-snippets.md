# Chapter 4 code snippets — Figures 4.16 to 4.21

Extracted verbatim from the working tree. Each figure gives its source file and line
range so a reader can open the real thing, and the ranges are the ones worth printing —
`RedactorService.php` alone is 268 lines and will not fit a figure.

Line numbers drift as the code changes. Re-check them before submitting: they were
correct at commit time and the surrounding prose in section 4.4.1 depends on them.

---

## Figure 4.16 — Personal Data Redaction

`backend/app/Services/RedactorService.php`, lines 113–131

The ordering is the whole point. URLs are *parked* before any other pattern runs and
restored afterwards, so a phishing link containing digits is never mistaken for an
account number. Phone numbers are claimed before bank accounts, because a Malaysian
mobile number and a short account number are both bare digit runs and whichever pattern
runs first wins.

```php
public function redact(string $text): RedactionResult
{
    $counts = [
        'emails' => 0,
        'ic_numbers' => 0,
        'phone_numbers' => 0,
        'bank_accounts' => 0,
    ];

    [$text, $urls] = $this->parkUrls($text);

    $text = $this->replace(self::PATTERN_EMAIL, self::MASK_EMAIL, $text, $counts['emails']);
    $text = $this->redactIcNumbers($text, $counts['ic_numbers']);
    $text = $this->replace(self::PATTERN_PHONE, self::MASK_PHONE, $text, $counts['phone_numbers']);
    $text = $this->replace(self::PATTERN_ACCOUNT, self::MASK_ACCOUNT, $text, $counts['bank_accounts']);
    $text = $this->redactGroupedAccounts($text, $counts['bank_accounts']);

    return new RedactionResult($this->restoreUrls($text, $urls), $counts);
}
```

### The call site — `backend/app/Http/Controllers/VerifyController.php`, lines 36–48

This is the half that proves the claim in section 4.4.1(a). Redaction runs before
`Submission::create()`, so unredacted content never becomes a database-bound value.

**Note the correction:** redaction is invoked from the controller, not from the form
request. See the note at the end of this file.

```php
public function store(VerifySubmissionRequest $request): View
{
    $type = $request->submissionType();
    $raw = $request->content();

    // Redact BEFORE anything is stored (AGENTS.md §3). The model still
    // sees the redacted text, which is deliberate: PII is not signal, and
    // RedactorService leaves URLs intact so the phishing link survives.
    $redaction = $this->redactor->redact($raw);

    $verdict = $this->detection->analyse($type, $redaction->text);

    $submission = Submission::query()->create([
        'type' => $type,
        'content_redacted' => $redaction->text,
        // ...
    ]);
```

---

## Figure 4.17 — Moderation Gate Enforcement

`backend/app/Models/TrainingSample.php`, lines 68–81 and 144–171

Enforced on the model rather than in a service class, so every write path is subject to
it — including paths that do not yet exist. Both `creating` and `updating` are guarded:
without the second, a row could be inserted under one provenance and quietly altered to
another afterwards.

```php
protected static function booted(): void
{
    static::creating(function (TrainingSample $sample): void {
        $sample->assertPromotionWasModerated();
    });

    static::updating(function (TrainingSample $sample): void {
        // Closes the obvious way around the creation check: insert a
        // corpus row, then quietly flip it to community afterwards.
        if ($sample->isDirty(['origin', 'feedback_id', 'approved_by'])) {
            $sample->assertPromotionWasModerated();
        }
    });
}
```

```php
public function assertPromotionWasModerated(): void
{
    $origin = $this->origin instanceof TrainingSampleOrigin
        ? $this->origin
        : TrainingSampleOrigin::tryFrom((string) $this->origin);

    if ($origin === null) {
        throw UnmoderatedPromotionException::unknownOrigin((string) $this->getAttribute('origin'));
    }

    if (! $origin->requiresApprovedFeedback()) {
        return;
    }

    if ($this->feedback_id === null) {
        throw UnmoderatedPromotionException::missingFeedback();
    }

    if ($this->approved_by === null) {
        throw UnmoderatedPromotionException::missingApprover();
    }

    $feedback = Feedback::query()->find($this->feedback_id);

    if ($feedback === null || ! $feedback->isApproved()) {
        throw UnmoderatedPromotionException::feedbackNotApproved($this->feedback_id);
    }
}
```

---

## Figure 4.18 — Threat Intelligence Cache

`backend/app/Models/ThreatIntelCache.php`, lines 72–85

Worth printing for the comment as much as the code. A scope named `fresh` would have
been silently shadowed by Eloquent's own `Model::fresh()` and never run, so the query
would have returned expired rows — which for a threat-intel cache means serving a stale
*"this URL is safe"* for a domain that had since been blocklisted.

```php
/**
 * Entries that are still within their 24-hour lifetime.
 *
 * Named `unexpired` rather than the more natural `fresh` because Eloquent's
 * Model already defines an instance method `fresh()` for reloading a record.
 * A `scopeFresh` would be shadowed by it and silently never run — the query
 * would return every row including expired ones, which for a threat-intel
 * cache means serving a stale "this URL is safe".
 *
 * @param  Builder<ThreatIntelCache>  $query
 */
public function scopeUnexpired(Builder $query): void
{
    $query->where('expires_at', '>', now());
}
```

`backend/app/Services/ThreatIntel/GoogleSafeBrowsingClient.php`, lines 94–122

```php
private function fromCache(string $hash): ?ThreatIntelResult
{
    $entry = ThreatIntelCache::query()
        ->where('provider', self::PROVIDER)
        ->where('url_hash', $hash)
        ->unexpired()
        ->first();

    if ($entry === null) {
        return null;
    }

    return $entry->is_malicious
        ? ThreatIntelResult::hit(self::PROVIDER, $entry->response ?? [])
        : ThreatIntelResult::clean(self::PROVIDER, $entry->response ?? []);
}

private function store(string $hash, ThreatIntelResult $result): void
{
    ThreatIntelCache::query()->updateOrCreate(
        ['provider' => self::PROVIDER, 'url_hash' => $hash],
        [
            'response' => $result->raw,
            'is_malicious' => $result->hit,
            'fetched_at' => now(),
            'expires_at' => now()->addSeconds((int) config('services.threat_intel.cache_ttl')),
        ],
    );
}
```

The cache is keyed on a hash of the URL rather than on a submission, which is why
`threat_intel_lookups` has no foreign key to `submissions`: one cached result serves
every subsequent submission of the same address.

---

## Figure 4.19 — Verified Institution Matching

`backend/app/Services/Detection/VerifiedDomainRegistry.php`, lines 91–126

The comment on the suffix branch is the security-relevant line: without the leading dot,
`notgov.my` would match the `gov.my` suffix, which is exactly the bypass this must not
permit.

```php
public function match(string $url): ?VerifiedInstitution
{
    $host = $this->hostOf($url);

    if ($host === null) {
        return null;
    }

    $registrable = $this->registrableDomain($host);

    if ($registrable === null) {
        return null;
    }

    if (isset($this->byDomain[$registrable])) {
        return $this->byDomain[$registrable];
    }

    foreach ($this->bySuffix as $suffix => $meta) {
        // Exact equality, or ends with ".{suffix}". The leading dot is
        // essential: without it "notgov.my" would match the "gov.my"
        // suffix, which is precisely the bypass this must not permit.
        if ($registrable === $suffix || str_ends_with($registrable, '.'.$suffix)) {
            return new VerifiedInstitution(
                domain: $registrable,
                name: (string) $meta['name'],
                authority: (string) $meta['authority'],
                reference: (string) $meta['reference'],
                verifiedOn: (string) $meta['verified_on'],
                bySuffix: true,
            );
        }
    }

    return null;
}
```

`backend/app/Services/Detection/VerifiedDomainRegistry.php`, lines 155–173

```php
private function registrableDomain(string $host): ?string
{
    $labels = explode('.', $host);
    $count = count($labels);

    if ($count < 2) {
        return null;
    }

    /** @var list<string> $multiPart */
    $multiPart = config('scamsense.multi_part_suffixes', []);

    $lastTwo = implode('.', array_slice($labels, -2));

    if (in_array($lastTwo, $multiPart, true)) {
        return $count >= 3 ? implode('.', array_slice($labels, -3)) : $lastTwo;
    }

    return $lastTwo;
}
```

Malaysian two-part suffixes are handled explicitly: without them `maybank2u.com.my`
would reduce to `com.my` and every Malaysian domain would collapse together.

---

## Figure 4.20 — HMAC Request Authentication

This figure needs both halves. Showing one side does not demonstrate the scheme, because
the signature is computed over `{timestamp}.{body}` on one side and recomputed on the
other.

### Signing — `backend/app/Services/Detection/MlServiceClient.php`, lines 85–95

```php
// Encode once and sign these exact bytes. Re-encoding for the request
// could reorder keys and produce a signature Flask cannot verify.
$body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$timestamp = (string) time();

$response = Http::withHeaders([
    self::TIMESTAMP_HEADER => $timestamp,
    self::SIGNATURE_HEADER => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    'Content-Type' => 'application/json',
])
```

### Verifying — `ml-service/app/security.py`, lines 93–103 and 141–164

```python
def compute_signature(secret: str, timestamp: str, body: bytes) -> str:
    """Produce the hex signature for a request.

    Laravel computes the identical value. The payload is
    `{timestamp}.{body}` — see the module docstring for why the timestamp is
    inside the signed material rather than beside it.
    """
    payload = timestamp.encode("utf-8") + b"." + body

    return hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).hexdigest()
```

```python
    current = time.time() if now is None else now

    # Rejects both stale requests and ones timestamped in the future; a clock
    # far ahead would otherwise widen the replay window indefinitely.
    if abs(current - sent_at) > ttl_seconds:
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    expected = compute_signature(secret, timestamp, body)

    # compare_digest, never ==. A plain comparison returns early on the first
    # differing byte, leaking the correct prefix through response timing.
    if not hmac.compare_digest(expected, signature):
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    # Replay: the signature is valid, so this is a genuine request — but it may
    # be the same genuine request arriving twice. The nonce is recorded only
    # after the signature verifies, so an attacker cannot burn nonces by
    # sending unsigned garbage.
    nonce = signature if request_id is None else f"{request_id}:{signature}"
    if not _remember_nonce(nonce, time.time() if now is None else now, ttl_seconds):
        return VerificationResult(False, "signature_invalid", "Signature verification failed.")

    return VerificationResult(True)
```

Three defensive properties are visible in that extract and are each worth a sentence:
the constant-time comparison, the bounded window that rejects future timestamps as well
as stale ones, and the nonce being recorded only *after* the signature verifies — so an
attacker cannot exhaust the nonce cache by sending unsigned garbage.

---

## Figure 4.21 — Graceful Degradation

`backend/app/Services/Detection/MlServiceClient.php`, lines 104–145

Five distinct failure modes, five distinct reasons, and not one of them reaches the user
as an error. Every path returns `MlPrediction::unavailable()`, which the detection
service turns into a partial verdict labelled as incomplete.

```php
} catch (ConnectionException $exception) {
    Log::warning('ML service unreachable.', ['reason' => SafeLog::reason($exception)]);

    return MlPrediction::unavailable('ml_service_unreachable');
} catch (Throwable $exception) {
    // Deliberately broad. Anything unexpected here must still degrade
    // to a partial verdict rather than reaching the user as an error.
    Log::error('ML service call failed.', ['reason' => SafeLog::reason($exception)]);

    return MlPrediction::unavailable('ml_service_error');
}

if ($response->status() === 503) {
    // The service is up but could not load a model for this task.
    return MlPrediction::unavailable('model_unavailable');
}

if (! $response->successful()) {
    Log::warning('ML service returned an error status.', [
        'status' => $response->status(),
        // The URL itself is deliberately not logged: it is user input,
        // and AGENTS.md §3 forbids writing unredacted submissions to
        // any log line.
        'path' => $path,
    ]);

    return MlPrediction::unavailable('ml_service_error');
}

$decoded = $response->json() ?? [];

if (! isset($decoded['label'], $decoded['confidence'])) {
    Log::warning('ML service returned a malformed body.', ['path' => $path]);

    return MlPrediction::unavailable('ml_service_malformed_response');
}

return MlPrediction::fromResponse($decoded);
```

`SafeLog::reason()` appearing in the two log calls is the fix for security review
findings 1 and 2 — the exception message is sanitised before it reaches the log, because
a connection-timeout exception embeds the full outbound request URL, which for one
provider carried the submitted URL inside its path.

The reason becomes a user-visible partial verdict in
`backend/app/Services/Detection/DetectionService.php`, lines 95–126.

---

## One correction needed in section 4.4.1(a)

The chapter currently states that redaction is *"implemented within the Laravel form
request, which executes before the model instance is created."*

The claim it supports is true — redaction does precede persistence — but the location is
wrong. `RedactorService` is injected into `VerifyController` (line 27) and invoked from
`store()` (line 44), before `Submission::create()` (line 48). The form request validates;
it does not redact.

Suggested replacement:

> Redaction is implemented as a service invoked by the controller before the model
> instance is created, so that unredacted content never becomes a database-bound value.
