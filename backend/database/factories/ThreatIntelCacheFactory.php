<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ThreatIntelCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreatIntelCache>
 */
class ThreatIntelCacheFactory extends Factory
{
    protected $model = ThreatIntelCache::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'google_safe_browsing',
            'url_hash' => ThreatIntelCache::hashUrl('https://'.fake()->domainName().'/login'),
            'response' => ['matches' => []],
            'is_malicious' => false,
            'fetched_at' => now(),
            'expires_at' => now()->addSeconds((int) config('services.threat_intel.cache_ttl')),
        ];
    }

    public function malicious(): static
    {
        return $this->state(fn (): array => [
            'is_malicious' => true,
            'response' => ['matches' => [['threatType' => 'SOCIAL_ENGINEERING']]],
        ]);
    }

    public function virustotal(): static
    {
        return $this->state(fn (): array => ['provider' => 'virustotal']);
    }

    /**
     * An entry past its 24-hour lifetime, for testing the Phase 9 purge and
     * the cache-miss path.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'fetched_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);
    }
}
