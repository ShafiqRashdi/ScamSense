<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionType;
use App\Enums\TrainingSampleOrigin;
use App\Enums\VerdictLabel;
use App\Models\TrainingSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSample>
 */
class TrainingSampleFactory extends Factory
{
    protected $model = TrainingSample::class;

    /**
     * Defaults to a corpus row.
     *
     * There is deliberately no `community()` state. A factory that could mint
     * community rows would be a code path around the moderation gate, and the
     * gate would reject it anyway — community rows are created by approving
     * feedback through FeedbackModerationService, which is the point.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->sentence();

        return [
            'feedback_id' => null,
            'submission_id' => null,
            'origin' => TrainingSampleOrigin::Corpus,
            'type' => SubmissionType::Message,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'label' => fake()->randomElement(VerdictLabel::cases()) === VerdictLabel::Unknown
                ? VerdictLabel::Scam
                : VerdictLabel::Scam,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function exported(string $version = 'msg-v1.0.0'): static
    {
        return $this->state(fn (): array => [
            'dataset_version' => $version,
            'exported_at' => now(),
        ]);
    }
}
