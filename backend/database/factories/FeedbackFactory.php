<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'verdict_id' => Verdict::factory(),
            'agrees' => fake()->boolean(70),
            'comment' => null,
            // Every item starts unmoderated. There is no factory state that
            // puts feedback anywhere near a model (AGENTS.md §3).
            'status' => FeedbackStatus::Pending,
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }

    public function disputed(): static
    {
        return $this->state(fn (): array => [
            'agrees' => false,
            'comment' => 'Saya rasa ini bukan penipuan, ini mesej rasmi bank saya.',
        ]);
    }

    public function approved(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => FeedbackStatus::Approved,
            'moderated_by' => $moderator?->id ?? User::factory(),
            'moderated_at' => now(),
        ]);
    }

    public function rejected(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => FeedbackStatus::Rejected,
            'moderated_by' => $moderator?->id ?? User::factory(),
            'moderated_at' => now(),
            'moderation_note' => 'Tiada bukti disertakan.',
        ]);
    }
}
