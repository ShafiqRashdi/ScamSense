<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;

/**
 * The fused verdict, ready to persist and render.
 *
 * `modelProbability` is the raw score the model produced, retained even when
 * the verified-domain gate has capped the risk level. ADR 0007 requires the
 * gate to change the band without altering the score, so that nothing
 * downstream ever measures a doctored number.
 */
final readonly class VerdictResult
{
    /**
     * @param  list<IndicatorData>  $indicators
     */
    public function __construct(
        public VerdictLabel $label,
        public RiskLevel $riskLevel,
        public float $confidence,
        public float $modelProbability,
        public array $indicators,
        public ?string $modelVersion,
        public bool $isPartial,
        public ?string $partialReason,
        public ?int $inferenceMs,
        public bool $gatedByVerifiedDomain = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toVerdictAttributes(): array
    {
        return [
            'label' => $this->label,
            'risk_level' => $this->riskLevel,
            'confidence' => round($this->confidence, 4),
            'model_version' => $this->modelVersion,
            'is_partial' => $this->isPartial,
            'partial_reason' => $this->partialReason,
            'inference_ms' => $this->inferenceMs,
        ];
    }
}
