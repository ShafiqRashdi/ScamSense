<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ThreatIntelCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached reputation answer from an external provider.
 *
 * Phase 5 adds the clients that populate this. The table exists now so that the
 * schema is settled in one place rather than accreting across phases.
 */
#[Fillable(['provider', 'url_hash', 'response', 'is_malicious', 'fetched_at', 'expires_at'])]
class ThreatIntelCache extends Model
{
    /** @use HasFactory<ThreatIntelCacheFactory> */
    use HasFactory;

    protected $table = 'threat_intel_cache';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_malicious' => 'boolean',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Hash a URL for use as the cache key.
     *
     * Normalised to lower case first so that `HTTP://Example.COM/x` and
     * `http://example.com/x` share a cache entry — scheme and host are
     * case-insensitive, and treating them otherwise would waste a quota that
     * allows four requests a minute.
     *
     * The path is deliberately left alone: it IS case-sensitive, and folding it
     * would collide two genuinely different URLs.
     */
    public static function hashUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            // Unparseable input should never reach here — UrlSafetyRule rejects
            // it at the FormRequest — but hashing the raw string is a safe
            // fallback that cannot collide with a well-formed URL's key.
            return hash('sha256', trim($url));
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return hash('sha256', "{$scheme}://{$host}{$port}{$path}{$query}");
    }

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
}
