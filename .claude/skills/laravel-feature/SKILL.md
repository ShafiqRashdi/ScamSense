---
name: laravel-feature
description: Use when creating or modifying anything in backend/ — routes, controllers, FormRequests, services, DTOs, Eloquent models, migrations, Blade views, or middleware. Covers the layering rules, validation, escaping, and test expectations for the ScamSense Laravel app.
---

# Laravel Feature Development

## Layering — always in this order

```
Route → Middleware (throttle, csrf) → Controller → FormRequest → Service → Model/Client → DTO → View/Resource
```

Skipping a layer is how this codebase rots. Specifically:

- **Controller** does three things only: receive a validated request, call one service method,
  return a response. No `if` chains on business rules, no query building, no HTTP calls.
- **FormRequest** owns every rule. Never `$request->input('url')` without it having passed a
  FormRequest first.
- **Service** owns business logic and is unit-testable without HTTP. Constructor-inject its
  dependencies; never `new` a client inside a method.
- **DTO** (readonly class in `app/DataTransferObjects/`) crosses every boundary. Arrays with
  string keys are banned between layers — they defeat static analysis and hide typos.

## Writing a new feature

1. Migration + factory first, then model.
2. DTO for the input and the output shape.
3. Service with the logic, plus its unit test.
4. FormRequest.
5. Controller + route.
6. Feature test covering happy path, each validation rejection, and the failure mode.
7. Blade view last.

## Validation rules for this project

```php
// VerifySubmissionRequest
'type'    => ['required', Rule::in(['message', 'url'])],
'content' => ['required', 'string', 'max:5000'],
// plus a conditional rule: when type=url, max:2048 and must pass a UrlSafetyRule
```

The custom `UrlSafetyRule` must reject anything whose scheme is not `http`/`https`. That single
rule blocks `javascript:`, `data:`, `file:`, and `vbscript:` payloads. Do not try to
regex-validate URLs — parse with `parse_url()` and check the parts.

## Escaping and rendering

- `{{ $value }}` always. `{!! !!}` is banned on anything that touched user input.
- Submitted content is rendered inside a `<pre>` with a defanged copy — run it through
  `DefangService` so `https://evil.com` displays as `hxxps://evil[.]com`.
- Never emit a submitted URL into an `href`, `src`, `action`, or inline `style`.
- Indicator codes map to translated strings via `lang/en/indicators.php` and
  `lang/ms/indicators.php`. Flask sends codes, Laravel sends prose.

## Persistence

- Store `content_hash` (SHA-256) alongside the redacted content. Dedupe on the hash.
- Run `RedactorService` before `->save()`, not after. Once raw PII is in MySQL it's in the
  backups too.
- Persist `model_version`, `confidence`, and the indicator set with each verdict so a screenshot
  in Chapter 4 can be traced to a specific model.

## External calls

Wrap `Http::` calls in a client class under `app/Services/ThreatIntel/`. Every client must set
`->timeout(3)->retry(1, 200)` and catch `ConnectionException`, returning a null-object result
rather than throwing into the controller. Cache lookups by URL hash for 24 hours — VirusTotal's
free tier is 4 requests/minute and a demo will blow through it.

## Tests to write every time

```php
it('rejects a javascript scheme url');
it('rejects content over the length limit');
it('throttles after 10 requests in a minute');
it('returns a partial verdict when the ml service times out'); // Http::fake()
it('escapes html in the rendered submission');                  // assertDontSee('<script>')
it('redacts a malaysian phone number before persisting');
```

`Http::fake()` for everything external. `RefreshDatabase` on feature tests. Run
`./vendor/bin/pint` and `php artisan test` before reporting the work as done.

## Frontend

Bootstrap 5 utilities, mobile-first. No jQuery. Vanilla JS or Alpine for the small amount of
interactivity needed. The verdict must be readable without colour alone — pair the colour with
an icon and a text label, since colour-only risk signalling fails WCAG 1.4.1 and fails the
older, less digitally literate user this project exists for.
