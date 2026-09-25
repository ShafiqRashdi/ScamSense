<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * One provider's answer about a URL.
 *
 * Three states, and the distinction between the last two is load-bearing
 * (ADR 0007):
 *
 *   - HIT       the provider positively reports this URL as malicious
 *   - CLEAN     the provider has no record of it
 *   - UNKNOWN   the provider could not be reached, was rate-limited, or has
 *               no key configured
 *
 * CLEAN is *not* evidence of safety. Every phishing domain registered in the
 * last few hours is clean on every blocklist, because blocklists are reactive.
 * So neither CLEAN nor UNKNOWN may lower a verdict; only HIT may raise one.
 * The two are kept apart anyway, because UNKNOWN means a signal was missing and
 * the verdict must be marked partial.
 */
final readonly class ThreatIntelResult
{
    private function __construct(
        public string $provider,
        public bool $hit,
        public bool $reachable,
        /** @var array<string, mixed> */
        public array $raw,
        public ?string $detail,
        public bool $configured = true,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function hit(string $provider, array $raw = [], ?string $detail = null): self
    {
        return new self($provider, true, true, $raw, $detail);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function clean(string $provider, array $raw = []): self
    {
        return new self($provider, false, true, $raw, null);
    }

    /**
     * A configured provider that could not answer — timeout, rate limit, error.
     *
     * This degrades the verdict: a signal that should have been there was not.
     */
    public static function unknown(string $provider, string $detail): self
    {
        return new self($provider, false, false, [], $detail);
    }

    /**
     * A provider with no API key in this deployment.
     *
     * Distinct from `unknown` and the distinction matters. ScamSense must run
     * without registered API keys, so treating "not configured" as a degraded
     * signal would mark *every* verdict partial — and a partial notice shown on
     * every result carries no information and trains users to ignore it, which
     * is worse than not having one.
     *
     * A provider that was never part of this deployment is not a missing
     * signal; it is a smaller system. Only a provider that should have
     * answered and did not degrades the verdict.
     */
    public static function notConfigured(string $provider): self
    {
        return new self($provider, false, false, [], 'no_api_key', configured: false);
    }

    /**
     * Whether this result carries information.
     *
     * An unreachable or unconfigured provider contributes nothing, and must
     * never be silently treated as a clean result.
     */
    public function isInformative(): bool
    {
        return $this->reachable;
    }

    /**
     * Whether this result's absence should mark the verdict partial.
     */
    public function degradesVerdict(): bool
    {
        return $this->configured && ! $this->reachable;
    }
}
