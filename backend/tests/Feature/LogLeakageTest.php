<?php

declare(strict_types=1);

use App\Services\Detection\MlServiceClient;
use App\Services\ThreatIntel\GoogleSafeBrowsingClient;
use App\Services\ThreatIntel\VirusTotalClient;
use App\Support\SafeLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Security review findings 1 and 2 — nothing sensitive reaches the log
|--------------------------------------------------------------------------
|
| The defect these guard against was not visible by reading the code. It lived
| in the HTTP client's exception message, which embeds the full request URL —
| carrying the Safe Browsing API key in a query parameter and the submitted URL
| as a base64url path segment.
|
| Both fired on the timeout path, which is the normal failure the code was
| written to handle gracefully.
|
*/

const FAKE_GSB_KEY = 'AIzaSyFAKEKEY0000000000000000000000000';
const FAKE_VT_KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const VICTIM_URL = 'https://victim-example.com/reset?token=SUPERSECRETTOKEN123456';

/**
 * Capture every log line written during a callback, at every level.
 *
 * @return list<string>
 */
function captureLog(callable $callback): array
{
    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->level.' '.$message->message.' '.json_encode($message->context);
    });

    $callback();

    return $lines;
}

beforeEach(function (): void {
    config()->set('services.safe_browsing.key', FAKE_GSB_KEY);
    config()->set('services.virustotal.key', FAKE_VT_KEY);
});

/*
|--------------------------------------------------------------------------
| The unit level
|--------------------------------------------------------------------------
*/

it('strips a query-string API key from a Guzzle connection message', function (): void {
    $message = 'cURL error 28: Connection timed out after 1003 milliseconds '
        .'(see https://curl.se/libcurl/c/libcurl-errors.html) for '
        .'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.FAKE_GSB_KEY;

    $scrubbed = SafeLog::scrub($message);

    expect($scrubbed)->not->toContain(FAKE_GSB_KEY)
        ->and($scrubbed)->not->toContain('safebrowsing.googleapis.com')
        ->and($scrubbed)->toContain('cURL error 28');
});

it('strips a base64url-encoded submitted URL from a request path', function (): void {
    // This is the shape that has no query string to give it away.
    $id = rtrim(strtr(base64_encode(VICTIM_URL), '+/', '-_'), '=');
    $message = "cURL error 7: Failed to connect for https://www.virustotal.com/api/v3/urls/{$id}";

    $scrubbed = SafeLog::scrub($message);

    expect($scrubbed)->not->toContain($id)
        ->and($scrubbed)->not->toContain('virustotal.com');

    // And the encoding must not be reversible out of what survived.
    expect(base64_decode(strtr($scrubbed, '-_', '+/'), true) ?: '')
        ->not->toContain('victim-example.com');
});

it('removes a configured secret even when it looks like an ordinary word', function (): void {
    config()->set('services.ml_service.secret', 'correcthorsebatterystaple');

    expect(SafeLog::scrub('signature check failed for correcthorsebatterystaple'))
        ->not->toContain('correcthorsebatterystaple');
});

it('leaves an ordinary diagnostic message readable', function (): void {
    // Over-scrubbing would make the log useless, which is its own failure.
    expect(SafeLog::scrub('Connection refused'))->toBe('Connection refused');
});

it('caps the length so a huge message cannot flood the log', function (): void {
    expect(strlen(SafeLog::scrub(str_repeat('word ', 500))))->toBeLessThanOrEqual(210);
});

/*
|--------------------------------------------------------------------------
| Through the real clients, on the real failure paths
|--------------------------------------------------------------------------
*/

it('never logs the API key when Safe Browsing times out', function (): void {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for '
        .'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.FAKE_GSB_KEY
    ));

    $lines = captureLog(fn () => app(GoogleSafeBrowsingClient::class)
        ->lookup('https://example.com/x'));

    expect($lines)->not->toBeEmpty();
    expect(implode("\n", $lines))->not->toContain(FAKE_GSB_KEY);
});

it('never logs the submitted URL when VirusTotal times out', function (): void {
    $id = rtrim(strtr(base64_encode(VICTIM_URL), '+/', '-_'), '=');

    Http::fake(fn () => throw new ConnectionException(
        "cURL error 28: Operation timed out for https://www.virustotal.com/api/v3/urls/{$id}"
    ));

    $lines = captureLog(fn () => app(VirusTotalClient::class)
        ->lookup(VICTIM_URL));

    $logged = implode("\n", $lines);

    expect($logged)->not->toContain($id)
        ->and($logged)->not->toContain('victim-example.com')
        ->and($logged)->not->toContain('SUPERSECRETTOKEN123456');
});

it('never logs the ML service secret when the internal hop fails', function (): void {
    config()->set('services.ml_service.secret', 'ml-secret-value-that-must-not-leak');

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 7: Failed to connect for http://127.0.0.1:5001/api/v1/predict/url'
        .'?debug=ml-secret-value-that-must-not-leak'
    ));

    $lines = captureLog(fn () => app(MlServiceClient::class)
        ->predictUrl('https://example.com'));

    expect(implode("\n", $lines))->not->toContain('ml-secret-value-that-must-not-leak');
});

/*
|--------------------------------------------------------------------------
| End to end — a full submission, every log level
|--------------------------------------------------------------------------
*/

it('leaks neither the submitted URL nor any key across a whole failed submission', function (): void {
    /*
     * The comprehensive assertion. Everything fails at once — the ML hop and
     * both providers — and nothing sensitive may appear at any level.
     */
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for '.VICTIM_URL.'?key='.FAKE_GSB_KEY
    ));

    $lines = captureLog(function (): void {
        $this->followingRedirects()->post('/verify', ['type' => 'url', 'content' => VICTIM_URL])->assertOk();
    });

    $logged = implode("\n", $lines);

    expect($logged)
        ->not->toContain(FAKE_GSB_KEY)
        ->not->toContain(FAKE_VT_KEY)
        ->not->toContain('SUPERSECRETTOKEN123456')
        ->not->toContain('victim-example.com');
});

it('leaks nothing through a submitted message either', function (): void {
    Http::fake(fn () => throw new ConnectionException('boom for https://x.test/'.str_repeat('a', 40)));

    $lines = captureLog(function (): void {
        $this->followingRedirects()->post('/verify', [
            'type' => 'message',
            'content' => 'Hubungi 012-345 6789 atau klik https://scam-example.test/pay?tok=ABCDEF1234567890',
        ])->assertOk();
    });

    $logged = implode("\n", $lines);

    expect($logged)
        ->not->toContain('012-345 6789')
        ->not->toContain('scam-example.test')
        ->not->toContain('ABCDEF1234567890');
});
