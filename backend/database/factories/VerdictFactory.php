<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;
use App\Models\Submission;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Verdict>
 */
class VerdictFactory extends Factory
{
    protected $model = Verdict::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'label' => VerdictLabel::Scam,
            'risk_level' => RiskLevel::High,
            'confidence' => fake()->randomFloat(4, 0.85, 0.99),
            'model_version' => 'distilbert-msg-v1.0.0',
            'is_partial' => false,
            'partial_reason' => null,
            'inference_ms' => fake()->numberBetween(80, 400),
        ];
    }

    public function legitimate(): static
    {
        return $this->state(fn (): array => [
            'label' => VerdictLabel::Legitimate,
            'risk_level' => RiskLevel::Low,
            'confidence' => fake()->randomFloat(4, 0.80, 0.98),
        ]);
    }

    public function medium(): static
    {
        return $this->state(fn (): array => [
            'label' => VerdictLabel::Scam,
            'risk_level' => RiskLevel::Medium,
            'confidence' => fake()->randomFloat(4, 0.50, 0.75),
        ]);
    }

    /**
     * The degraded case: the ML service was unreachable, so no model ran.
     *
     * AGENTS.md §2 rule 4 requires the user still see a verdict, labelled as
     * partial. Phase 5's fallback test asserts against exactly this shape.
     */
    public function partial(string $reason = 'ml_service_unavailable'): static
    {
        return $this->state(fn (): array => [
            'label' => VerdictLabel::Unknown,
            'risk_level' => RiskLevel::Unknown,
            'confidence' => 0.0,
            'model_version' => null,
            'is_partial' => true,
            'partial_reason' => $reason,
            'inference_ms' => null,
        ]);
    }
}
