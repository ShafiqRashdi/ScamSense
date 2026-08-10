<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionType;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->sentence();

        return [
            'type' => SubmissionType::Message,
            'content_redacted' => $content,
            'content_hash' => Submission::hashContent($content),
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }

    /**
     * A submission whose content is already redacted text with the given body.
     *
     * Keeps `content_hash` consistent with the content, which a plain
     * `->state(['content_redacted' => ...])` would silently break.
     */
    public function withContent(string $content): static
    {
        return $this->state(fn (): array => [
            'content_redacted' => $content,
            'content_hash' => Submission::hashContent($content),
        ]);
    }

    public function url(): static
    {
        $url = 'https://'.Str::random(8).'.example.com/verify';

        return $this->state(fn (): array => [
            'type' => SubmissionType::Url,
            'content_redacted' => $url,
            'content_hash' => Submission::hashContent($url),
        ]);
    }
}
