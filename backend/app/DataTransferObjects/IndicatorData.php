<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\IndicatorSource;

/**
 * One reason contributing to a verdict, as it crosses service boundaries.
 *
 * The ML service sends codes; this object carries them until Indicator rows are
 * written. No user-facing prose lives here — translation happens at render time
 * through lang/en and lang/ms (AGENTS.md §4).
 */
final readonly class IndicatorData
{
    public function __construct(
        public string $code,
        public float $weight,
        public ?string $evidence,
        public IndicatorSource $source,
    ) {}

    /**
     * Build from one entry of the ML service's `indicators` array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromMlResponse(array $payload): self
    {
        return new self(
            code: (string) ($payload['code'] ?? 'unknown'),
            weight: (float) ($payload['weight'] ?? 0.0),
            evidence: isset($payload['evidence']) ? (string) $payload['evidence'] : null,
            source: IndicatorSource::tryFrom((string) ($payload['source'] ?? ''))
                ?? IndicatorSource::Model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'weight' => $this->weight,
            'evidence' => $this->evidence,
            'source' => $this->source->value,
        ];
    }
}
