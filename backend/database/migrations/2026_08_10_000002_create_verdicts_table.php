<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The result shown to the user for one submission.
 *
 * Everything needed to reproduce a screenshot months later is stored on the row
 * itself — `model_version`, `confidence`, the risk band and whether the models
 * actually ran. AGENTS.md §4 is explicit that a panel may ask which model
 * produced a given result, and a verdict that cannot answer that is not
 * evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdicts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('submission_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('label', 16);
            $table->string('risk_level', 16);

            // decimal, not float: confidence is compared and averaged for the
            // Phase 8 dashboard, and binary floating point makes those
            // comparisons quietly unreliable. Four places is well beyond the
            // precision either model meaningfully reports.
            $table->decimal('confidence', 5, 4);

            // Null when no model ran — see is_partial below.
            $table->string('model_version', 64)->nullable();

            /*
             * AGENTS.md §2 rule 4: when the ML service or a threat-intel
             * provider is unavailable, the user still gets a verdict, clearly
             * labelled as partial, rather than a 500. That labelling has to be
             * persisted rather than computed at render time, because whether
             * the model ran is a historical fact about this specific verdict.
             * A screenshot of a partial verdict must stay identifiable as one.
             */
            $table->boolean('is_partial')->default(false);
            $table->string('partial_reason', 64)->nullable();

            // Reported by the ML service so the O2 latency budget can be
            // evidenced from real rows rather than asserted.
            $table->unsignedInteger('inference_ms')->nullable();

            $table->timestamps();

            $table->index(['label', 'created_at']);
            $table->index('risk_level');
            $table->index('model_version');
            $table->index('is_partial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdicts');
    }
};
