<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackStatus;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A community report agreeing with or disputing a verdict (objective O3).
 *
 * Note what this class deliberately does not have: any method that promotes
 * feedback towards a model. Approval moves a row into an export set and no
 * further (AGENTS.md §3).
 */
#[Fillable(['verdict_id', 'agrees', 'comment', 'status', 'ip_hash'])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    /**
     * Laravel would pluralise this to `feedbacks`, which is not a word.
     */
    protected $table = 'feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agrees' => 'boolean',
            'status' => FeedbackStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Verdict, $this>
     */
    public function verdict(): BelongsTo
    {
        return $this->belongsTo(Verdict::class);
    }

    /**
     * The administrator who approved or rejected this item, if anyone has.
     *
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * @return HasOne<TrainingSample, $this>
     */
    public function trainingSample(): HasOne
    {
        return $this->hasOne(TrainingSample::class);
    }

    public function isApproved(): bool
    {
        return $this->status === FeedbackStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === FeedbackStatus::Pending;
    }

    /**
     * The moderation queue: unreviewed items, oldest first.
     *
     * @param  Builder<Feedback>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', FeedbackStatus::Pending)->oldest();
    }

    /**
     * The only rows eligible for export into a retraining dataset.
     *
     * @param  Builder<Feedback>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', FeedbackStatus::Approved);
    }
}
