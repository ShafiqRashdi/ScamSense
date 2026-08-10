<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndicatorSource;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reason contributing to a verdict.
 *
 * `code` is a closed enum shared with the Flask service. The translated prose a
 * user reads comes from lang/en/indicators.php and lang/ms/indicators.php, keyed
 * on this code — the ML service never sends prose (AGENTS.md §4).
 */
#[Fillable(['verdict_id', 'code', 'weight', 'evidence', 'source'])]
class Indicator extends Model
{
    /** @use HasFactory<IndicatorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'source' => IndicatorSource::class,
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
     * The translation key for this indicator's user-facing description.
     *
     * Falls back to the raw code if a translation is missing, which is visible
     * and fixable, rather than rendering an empty string that looks like the
     * model had nothing to say.
     */
    public function translationKey(): string
    {
        return "indicators.{$this->code}";
    }
}
