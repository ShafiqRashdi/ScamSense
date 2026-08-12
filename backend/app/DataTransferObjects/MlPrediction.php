<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\VerdictLabel;

/**
 * A prediction from the ML service, or the fact that one could not be obtained.
 *
 * A null object rather than an exception when the service is unavailable: the
 * detection flow must degrade to a partial verdict, never throw into the
 * controller (AGENTS.md §2, rule 4). Callers check {@see self::isAvailable()}
 * rather than catching.
 */
final readonly class MlPrediction
{
    /**
     * @param  list<IndicatorData>  $indicators
     */
    private function __construct(
        public bool $available,
        public ?VerdictLabel $label,
        public float $probability,
        public array $indicators,
        public ?string $modelVersion,
        public ?int $inferenceMs,
        public ?string $unavailableReason,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $label = VerdictLabel::tryFrom((string) ($payload['label'] ?? ''))
            ?? VerdictLabel::Unknown;

        $confidence = (float) ($payload['confidence'] ?? 0.0);

        /*
         * The service reports confidence FOR THE LABEL IT RETURNED, so a
         * "legitimate" verdict at 0.98 means 0.02 probability of being a scam.
         * Converting back to a single scam-probability here keeps the banding
         * logic in DetectionService working on one consistent scale — banding
         * a number that flips meaning with the label would be a silent bug.
         */
        $probability = $label === VerdictLabel::Scam ? $confidence : 1.0 - $confidence;

        $indicators = array_map(
            static fn (array $indicator): IndicatorData => IndicatorData::fromMlResponse($indicator),
            array_values((array) ($payload['indicators'] ?? [])),
        );

        return new self(
            available: true,
            label: $label,
            probability: $probability,
            indicators: $indicators,
            modelVersion: isset($payload['model_version'])
                ? (string) $payload['model_version']
                : null,
            inferenceMs: isset($payload['inference_ms'])
                ? (int) $payload['inference_ms']
                : null,
            unavailableReason: null,
        );
    }

    public static function unavailable(string $reason): self
    {
        return new self(
            available: false,
            label: null,
            probability: 0.0,
            indicators: [],
            modelVersion: null,
            inferenceMs: null,
            unavailableReason: $reason,
        );
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
