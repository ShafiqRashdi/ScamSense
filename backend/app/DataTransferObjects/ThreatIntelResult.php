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

    public static function unknown(string $provider, string $detail): self
    {
        return new self($provider, false, false, [], $detail);
    }

    /**
     * Whether this result carries information at all.
     *
     * Named so that call sites read as intent rather than as a boolean check:
     * an unreachable provider contributes nothing, and must not be silently
     * treated as a clean result.
     */
    public function isInformative(): bool
    {
        return $this->reachable;
    }
}
