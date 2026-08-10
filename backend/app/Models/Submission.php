<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionType;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A message or URL a user asked ScamSense about.
 *
 * `content_redacted` is exactly what its name says: the text has already been
 * through RedactorService. Nothing in this application should ever write raw
 * user input to this model.
 */
#[Fillable(['type', 'content_redacted', 'content_hash', 'ip_hash'])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SubmissionType::class,
        ];
    }

    /**
     * @return HasMany<Verdict, $this>
     */
    public function verdicts(): HasMany
    {
        return $this->hasMany(Verdict::class);
    }

    /**
     * The verdict currently shown for this submission.
     *
     * A submission can accumulate more than one verdict over time — re-running
     * a newer model over old submissions is how Phase 3's retrain gets
     * evaluated against real traffic — so "the" verdict is the most recent one.
     *
     * @return HasOne<Verdict, $this>
     */
    public function latestVerdict(): HasOne
    {
        return $this->hasOne(Verdict::class)->latestOfMany();
    }

    /**
     * Compute the deduplication hash for a piece of redacted content.
     *
     * Kept on the model so that the seeder, the detection service and any
     * future import script cannot drift into computing it three different
     * ways — a dedup key that disagrees with itself silently stops deduping.
     *
     * Pass content that has ALREADY been redacted. See the migration for why
     * the hash is taken after redaction rather than before.
     */
    public static function hashContent(string $redactedContent): string
    {
        return hash('sha256', trim($redactedContent));
    }
}
