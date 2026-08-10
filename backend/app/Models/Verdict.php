<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;
use Database\Factories\VerdictFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'submission_id',
    'label',
    'risk_level',
    'confidence',
    'model_version',
    'is_partial',
    'partial_reason',
    'inference_ms',
])]
class Verdict extends Model
{
    /** @use HasFactory<VerdictFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => VerdictLabel::class,
            'risk_level' => RiskLevel::class,
            // Cast to string, not float: the column is DECIMAL precisely so
            // that comparisons stay exact, and casting to float here would give
            // that back. Callers wanting arithmetic can cast at the point of use.
            'confidence' => 'decimal:4',
            'is_partial' => 'boolean',
            'inference_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Whether a model actually produced this verdict.
     *
     * The UI must say so when one did not (AGENTS.md §2, rule 4). Reading it
     * through a named accessor rather than testing `is_partial` inline keeps
     * that check greppable.
     */
    public function isFromModel(): bool
    {
        return ! $this->is_partial && $this->model_version !== null;
    }
}
