<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 | `scamsense:doctor` exists because of a failure that is invisible from every
 | surface that looks like it should reveal it: when the Flask process holds a
 | different ML_SERVICE_SECRET than this application, the service is genuinely
 | healthy — listening, artefacts loaded, /health returning models_loaded true —
 | while every signed request comes back 401 and every verdict silently degrades
 | to "we could not check this properly".
 |
 | The tests below pin the two halves of that: a healthy service is not enough
 | to pass, and a 401 must be reported rather than swallowed.
 */

function healthOk(): array
{
    return [
        'status' => 'ok',
        'service' => 'scamsense-ml',
        'version' => '0.2.0',
        'models' => ['message' => 'tfidf-msg-v2', 'url' => 'rf-url-v2'],
        'models_loaded' => true,
    ];
}

function predictionOk(): array
{
    return [
        'request_id' => 'doctor',
        'label' => 'scam',
        'confidence' => 0.91,
        'indicators' => [],
        'model_version' => 'rf-url-v2',
        'inference_ms' => 11,
    ];
}

beforeEach(function (): void {
    config(['services.ml_service.secret' => str_repeat('a', 64)]);
});

it('passes when the whole chain answers', function (): void {
    Http::fake([
        '*/health' => Http::response(healthOk()),
        '*/predict/*' => Http::response(predictionOk()),
    ]);

    $this->artisan('scamsense:doctor')->assertSuccessful();
});

it('fails when the service is healthy but rejects signed requests', function (): void {
    /*
     | The regression this command was written for. /health answers perfectly
     | and models_loaded is true; only the signed call reveals the mismatch, so
     | a check that stopped at /health would report everything fine.
     */
    Http::fake([
        '*/health' => Http::response(healthOk()),
        '*/predict/*' => Http::response(['error' => 'signature_invalid'], 401),
    ]);

    $this->artisan('scamsense:doctor')
        ->expectsOutputToContain('DIFFERENT secret')
        ->assertFailed();
});

it('fails when an artefact did not load', function (): void {
    Http::fake([
        '*/health' => Http::response(healthOk()),
        '*/predict/*' => Http::response(['error' => 'model_unavailable'], 503),
    ]);

    $this->artisan('scamsense:doctor')->assertFailed();
});

it('fails when nothing is listening', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('scamsense:doctor')->assertFailed();
});

it('fails when the shared secret is not configured', function (): void {
    config(['services.ml_service.secret' => '']);
    Http::fake(['*' => Http::response(healthOk())]);

    $this->artisan('scamsense:doctor')->assertFailed();
});

it('never prints the shared secret', function (): void {
    // The command is run on a shared screen before a demo.
    $secret = str_repeat('a', 64);
    config(['services.ml_service.secret' => $secret]);

    Http::fake([
        '*/health' => Http::response(healthOk()),
        '*/predict/*' => Http::response(predictionOk()),
    ]);

    $this->artisan('scamsense:doctor')
        ->doesntExpectOutputToContain($secret)
        ->assertSuccessful();
});
