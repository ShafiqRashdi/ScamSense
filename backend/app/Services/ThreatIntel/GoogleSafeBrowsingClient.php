<?php

declare(strict_types=1);

namespace App\Services\ThreatIntel;

use App\DataTransferObjects\ThreatIntelResult;
use App\Models\ThreatIntelCache;
use App\Support\SafeLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Safe Browsing reputation lookup.
 *
 * **This sends the URL to Google's API. It never fetches the URL itself**
 * (AGENTS.md §2, rule 3) — no cURL to user input, no redirect following, no
 * favicon. The only outbound request is to a fixed Google endpoint with the
 * submitted URL in the request body.
 *
 * Never throws: an unreachable provider returns
 * {@see ThreatIntelResult::unknown()} so the verdict degrades to partial rather
 * than failing.
 */
class GoogleSafeBrowsingClient
{
    public const PROVIDER = 'google_safe_browsing';

    private const ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    public function lookup(string $url): ThreatIntelResult
    {
        $key = (string) config('services.safe_browsing.key');

        if ($key === '') {
            // Not an error. The project must run without paid or registered
            // keys, degrading to model-only analysis.
            return ThreatIntelResult::notConfigured(self::PROVIDER);
        }

        $hash = ThreatIntelCache::hashUrl($url);
        $cached = $this->fromCache($hash);

        if ($cached instanceof ThreatIntelResult) {
            return $cached;
        }

        try {
            $response = Http::timeout((int) config('services.safe_browsing.timeout', 3))
                ->retry((int) config('services.safe_browsing.retries', 1), 200, throw: false)
                ->post(self::ENDPOINT.'?key='.urlencode($key), [
                    'client' => ['clientId' => 'scamsense', 'clientVersion' => '1.0.0'],
                    'threatInfo' => [
                        'threatTypes' => [
                            'MALWARE',
                            'SOCIAL_ENGINEERING',
                            'UNWANTED_SOFTWARE',
                            'POTENTIALLY_HARMFUL_APPLICATION',
                        ],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [['url' => $url]],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Safe Browsing unreachable.', ['reason' => SafeLog::reason($exception)]);

            return ThreatIntelResult::unknown(self::PROVIDER, 'unreachable');
        } catch (Throwable $exception) {
            Log::error('Safe Browsing call failed.', ['reason' => SafeLog::reason($exception)]);

            return ThreatIntelResult::unknown(self::PROVIDER, 'error');
        }

        if (! $response->successful()) {
            return ThreatIntelResult::unknown(self::PROVIDER, 'http_'.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $matches = (array) ($body['matches'] ?? []);

        $result = $matches === []
            ? ThreatIntelResult::clean(self::PROVIDER, $body)
            : ThreatIntelResult::hit(self::PROVIDER, $body, (string) ($matches[0]['threatType'] ?? ''));

        $this->store($hash, $result);

        return $result;
    }

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
}
