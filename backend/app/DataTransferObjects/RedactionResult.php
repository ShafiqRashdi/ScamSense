<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The outcome of running text through RedactorService.
 *
 * A DTO rather than a bare string because callers need two things: the safe
 * text to store, and what was found. The counts drive the "we removed personal
 * details from your message" notice in the Phase 6 UI, and let Phase 8 report
 * how often submissions contain PII without ever storing the PII itself.
 */
final readonly class RedactionResult
{
    /**
     * @param  array<string, int>  $counts  Keyed by pattern name: emails, ic_numbers,
     *                                      phone_numbers, bank_accounts.
     */
    public function __construct(
        public string $text,
        public array $counts,
    ) {}

    public function redactedAnything(): bool
    {
        return $this->total() > 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * The pattern names that matched at least once.
     *
     * @return list<string>
     */
    public function matchedPatterns(): array
    {
        return array_values(array_keys(array_filter($this->counts)));
    }
}
