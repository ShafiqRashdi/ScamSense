<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IndicatorSource;
use App\Models\Indicator;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    protected $model = Indicator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'verdict_id' => Verdict::factory(),
            'code' => fake()->randomElement([
                'urgency_language',
                'prize_claim',
                'credential_request',
                'payment_demand',
            ]),
            'weight' => fake()->randomFloat(4, 0.05, 0.45),
            'evidence' => fake()->words(3, true),
            'source' => IndicatorSource::Pattern,
        ];
    }

    public function fromModel(): static
    {
        return $this->state(fn (): array => ['source' => IndicatorSource::Model]);
    }

    public function fromThreatIntel(): static
    {
        return $this->state(fn (): array => [
            'source' => IndicatorSource::ThreatIntel,
            'code' => 'threat_intel_hit',
        ]);
    }

    public function code(string $code, ?string $evidence = null, float $weight = 0.25): static
    {
        return $this->state(fn (): array => [
            'code' => $code,
            'evidence' => $evidence,
            'weight' => $weight,
        ]);
    }
}
