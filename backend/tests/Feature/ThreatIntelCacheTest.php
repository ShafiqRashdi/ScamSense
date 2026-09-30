<?php

declare(strict_types=1);

use App\Models\ThreatIntelCache;
use App\Services\ThreatIntel\GoogleSafeBrowsingClient;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The threat-intel cache never stores the submitted URL
|--------------------------------------------------------------------------
|
| The cache is keyed on url_hash so the URL itself is not kept (Chapter 4,
| Table 4.4). Safe Browsing's match response echoes the URL back in
| `threat.url`, and until 2026-10-01 the whole body was cached, so every
| flagged URL sat in plain text beside the hash meant to replace it. Found on
| the live site with Google's test URL. The same shape as security review
| findings 1 and 2: a submitted URL re-entering through an outbound API call.
|
*/

const CACHE_TEST_GSB_KEY = 'AIzaSyCACHETEST000000000000000000000000';
const CACHE_TEST_URL = 'https://flagged-victim-example.com/login?session=PRIVATE123';

beforeEach(function (): void {
    config()->set('services.safe_browsing.key', CACHE_TEST_GSB_KEY);
});

function fakeSafeBrowsingMatch(): void
{
    Http::fake([
        'safebrowsing.googleapis.com/*' => Http::response([
            'matches' => [[
                'threatType' => 'SOCIAL_ENGINEERING',
                'platformType' => 'ANY_PLATFORM',
                'threat' => ['url' => CACHE_TEST_URL],
                'cacheDuration' => '300s',
                'threatEntryType' => 'URL',
            ]],
        ]),
    ]);
}

it('caches a Safe Browsing match without the submitted URL', function (): void {
    fakeSafeBrowsingMatch();

    $result = app(GoogleSafeBrowsingClient::class)->lookup(CACHE_TEST_URL);

    expect($result->hit)->toBeTrue();

    $stored = (string) ThreatIntelCache::query()->sole()->getRawOriginal('response');

    expect($stored)->not->toContain('flagged-victim-example')
        ->and($stored)->not->toContain('PRIVATE123')
        ->and(json_decode($stored, true))->toEqual([
            'threat_type' => 'SOCIAL_ENGINEERING',
            'match_count' => 1,
        ]);
});

it('still reports the hit, with its threat type, when served from the cache', function (): void {
    fakeSafeBrowsingMatch();
    $client = app(GoogleSafeBrowsingClient::class);

    $client->lookup(CACHE_TEST_URL);
    $cached = $client->lookup(CACHE_TEST_URL);

    Http::assertSentCount(1);
    expect($cached->hit)->toBeTrue()
        ->and($cached->detail)->toBe('SOCIAL_ENGINEERING');
});

it('caches a clean Safe Browsing answer as an empty summary', function (): void {
    Http::fake(['safebrowsing.googleapis.com/*' => Http::response([])]);

    $result = app(GoogleSafeBrowsingClient::class)->lookup(CACHE_TEST_URL);

    expect($result->hit)->toBeFalse()
        ->and(ThreatIntelCache::query()->sole()->response)
        ->toEqual(['threat_type' => '', 'match_count' => 0]);
});
