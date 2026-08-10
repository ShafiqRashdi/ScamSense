<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The human-readable reasons behind a verdict.
 *
 * A row per indicator rather than a JSON column on `verdicts`. The Phase 8
 * dashboard aggregates indicator frequency across verdicts, which is a GROUP BY
 * on an indexed column here and a full table scan with JSON extraction there.
 *
 * `code` is a closed enum shared with the Flask service (AGENTS.md §4). Flask
 * sends codes only; Laravel maps them to Bahasa Malaysia and English prose
 * through lang/ms and lang/en. No user-facing text is ever stored here, which
 * is what makes the interface translatable without touching the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('verdict_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code', 64);
            $table->decimal('weight', 5, 4);

            /*
             * A short excerpt showing what triggered the indicator, e.g.
             * "segera dalam 24 jam". This is user-submitted text, so it is
             * redacted on the way in like everything else, and it is rendered
             * with {{ }} and never as HTML.
             */
            $table->string('evidence', 255)->nullable();

            // See App\Enums\IndicatorSource: distinguishes real model
            // attributions from the curated pattern layer, so the hybrid
            // explainability approach stays honest and queryable.
            $table->string('source', 16);

            $table->timestamps();

            $table->index(['code', 'created_at']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
