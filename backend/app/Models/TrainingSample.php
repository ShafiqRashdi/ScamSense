<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionType;
use App\Enums\TrainingSampleOrigin;
use App\Enums\VerdictLabel;
use App\Exceptions\UnmoderatedPromotionException;
use Database\Factories\TrainingSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate row for a future retraining set.
 *
 * ## The gate
 *
 * AGENTS.md §3 requires that crowdsourced labels never reach a model directly.
 * That guarantee is enforced *here*, in a `creating` hook, rather than in the
 * service that normally creates these rows.
 *
 * The reason is that a rule living only in a service is a rule that holds until
 * someone writes a second path to the same table — a bulk action, a seeder, an
 * import command, a quick `TrainingSample::create()` in tinker. Each of those
 * would be a reasonable-looking piece of code and each would silently dismantle
 * the project's central security claim. Putting the invariant on the model
 * means every one of those paths hits it, including ones not yet written.
 *
 * A community-origin row cannot exist unless:
 *   1. it references a feedback record,
 *   2. that record's status is Approved, and
 *   3. an administrator is recorded as the approver.
 *
 * Violations throw {@see UnmoderatedPromotionException} rather than failing
 * quietly, because a silent skip would leave the caller believing promotion
 * had happened.
 *
 * ## What this does not claim
 *
 * A row here is approved, not trained on. Export into a numbered dataset
 * version is a separate deliberate act, and training on that version is a third.
 * The gap is intentional: three explicit steps between a stranger's opinion and
 * a model's weights.
 */
#[Fillable([
    'feedback_id',
    'submission_id',
    'origin',
    'type',
    'content',
    'content_hash',
    'label',
    'approved_by',
    'approved_at',
    'dataset_version',
    'exported_at',
])]
class TrainingSample extends Model
{
    /** @use HasFactory<TrainingSampleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (TrainingSample $sample): void {
            $sample->assertPromotionWasModerated();
        });

        static::updating(function (TrainingSample $sample): void {
            // Closes the obvious way around the creation check: insert a
            // corpus row, then quietly flip it to community afterwards.
            if ($sample->isDirty(['origin', 'feedback_id', 'approved_by'])) {
                $sample->assertPromotionWasModerated();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => TrainingSampleOrigin::class,
            'type' => SubmissionType::class,
            'label' => VerdictLabel::class,
            'approved_at' => 'datetime',
            'exported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Feedback, $this>
     */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Rows approved but not yet part of any exported dataset version.
     *
     * @param  Builder<TrainingSample>  $query
     */
    public function scopeUnexported(Builder $query): void
    {
        $query->whereNull('dataset_version');
    }

    /**
     * @param  Builder<TrainingSample>  $query
     */
    public function scopeFromCommunity(Builder $query): void
    {
        $query->where('origin', TrainingSampleOrigin::Community);
    }

    /**
     * The invariant. See the class docblock.
     *
     * @throws UnmoderatedPromotionException
     */
    public function assertPromotionWasModerated(): void
    {
        $origin = $this->origin instanceof TrainingSampleOrigin
            ? $this->origin
            : TrainingSampleOrigin::tryFrom((string) $this->origin);

        if ($origin === null) {
            throw UnmoderatedPromotionException::unknownOrigin((string) $this->getAttribute('origin'));
        }

        if (! $origin->requiresApprovedFeedback()) {
            return;
        }

        if ($this->feedback_id === null) {
            throw UnmoderatedPromotionException::missingFeedback();
        }

        if ($this->approved_by === null) {
            throw UnmoderatedPromotionException::missingApprover();
        }

        $feedback = Feedback::query()->find($this->feedback_id);

        if ($feedback === null || ! $feedback->isApproved()) {
            throw UnmoderatedPromotionException::feedbackNotApproved($this->feedback_id);
        }
    }
}
