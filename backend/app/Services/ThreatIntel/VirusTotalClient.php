<?php

declare(strict_types=1);

namespace App\Services\ThreatIntel;

use App\DataTransferObjects\ThreatIntelResult;
use App\Models\ThreatIntelCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * VirusTotal URL reputation lookup.
 *
 * **Reads an existing report only.** The client never submits a URL for
 * scanning, because asking VirusTotal to scan a URL causes *their*
 * infrastructure to fetch it — which would make ScamSense the cause of a fetch
 * of user-supplied input, defeating AGENTS.md §2 rule 3 by proxy. A 404 from
 * the report endpoint means "no report exists", which is treated as clean.
 *
 * The 24-hour cache is a functional requirement, not an optimisation: the free
 * tier allows four requests a minute, which a live demonstration would exhaust
 * in seconds.
 */
class VirusTotalClient
{
    public const PROVIDER = 'virustotal';

    private const ENDPOINT = 'https://www.virustotal.com/api/v3/urls/';

    /**
     * Malicious-engine count at or above which the URL is treated as a hit.
     *
     * One engine flagging a URL is frequently a false positive; requiring two
     * independent engines materially reduces that without weakening detection
     * of anything genuinely circulating.
     */
    private const MALICIOUS_THRESHOLD = 2;

    public function lookup(string $url): ThreatIntelResult
    {
        $key = (string) config('services.virustotal.key');

        if ($key === '') {
            return ThreatIntelResult::notConfigured(self::PROVIDER);
        }

        $hash = ThreatIntelCache::hashUrl($url);
        $cached = $this->fromCache($hash);

        if ($cached instanceof ThreatIntelResult) {
            return $cached;
        }

        try {
            $response = Http::withHeaders(['x-apikey' => $key])
                ->timeout((int) config('services.virustotal.timeout', 3))
                ->retry((int) config('services.virustotal.retries', 1), 200, throw: false)
                ->get(self::ENDPOINT.$this->urlId($url));
        } catch (ConnectionException $exception) {
            Log::warning('VirusTotal unreachable.', ['reason' => $exception->getMessage()]);

            return ThreatIntelResult::unknown(self::PROVIDER, 'unreachable');
        } catch (Throwable $exception) {
            Log::error('VirusTotal call failed.', ['reason' => $exception->getMessage()]);

            return ThreatIntelResult::unknown(self::PROVIDER, 'error');
        }

        if ($response->status() === 404) {
            // No report exists. Genuinely no information, but it is the normal
            // answer for anything not yet analysed, so it is cached as clean
            // rather than burning quota on repeats.
            $result = ThreatIntelResult::clean(self::PROVIDER, ['found' => false]);
            $this->store($hash, $result);

            return $result;
        }

        if ($response->status() === 429) {
            // Rate limited — no information, and must not be cached as clean.
            return ThreatIntelResult::unknown(self::PROVIDER, 'rate_limited');
        }

        if (! $response->successful()) {
            return ThreatIntelResult::unknown(self::PROVIDER, 'http_'.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $stats = (array) data_get($body, 'data.attributes.last_analysis_stats', []);
        $malicious = (int) ($stats['malicious'] ?? 0) + (int) ($stats['suspicious'] ?? 0);

        $summary = ['malicious' => $stats['malicious'] ?? 0, 'suspicious' => $stats['suspicious'] ?? 0];

        $result = $malicious >= self::MALICIOUS_THRESHOLD
            ? ThreatIntelResult::hit(self::PROVIDER, $summary, "{$malicious} engines")
            : ThreatIntelResult::clean(self::PROVIDER, $summary);

        $this->store($hash, $result);

        return $result;
    }

    /**
     * VirusTotal identifies a URL by its unpadded base64url SHA-256.
     */
    private function urlId(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
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
