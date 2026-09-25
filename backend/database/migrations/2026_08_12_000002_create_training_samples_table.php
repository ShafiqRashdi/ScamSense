<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The versioned retraining dataset — the far end of objective O3.
 *
 * This is the ONLY table community feedback can ever reach, and it can only be
 * reached through explicit administrator approval (AGENTS.md §3, ADR 0008).
 * A row here is a candidate for a future training run; it is not training data
 * until a human deliberately exports a dataset version and trains on it.
 *
 * ## Provenance is the point of the `origin` and `feedback_id` columns
 *
 * Chapter 4 has to be able to say exactly which retraining rows came from users
 * and which came from the original corpus. `origin` answers that in aggregate
 * and `feedback_id` answers it per row, right back to the moderation decision
 * and the administrator who made it. Without those, a v2 model could not be
 * described honestly — "we retrained on community data" would be unauditable.
 *
 * ## `label` is the moderator's label, not the user's opinion
 *
 * A user disputing a verdict is a signal that something is worth reviewing, not
 * a label. The administrator decides what the correct label actually is, and
 * that decision is what lands here. Copying the user's assertion straight in
 * would be the poisoning path this whole design exists to close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_samples', function (Blueprint $table): void {
            $table->id();

            /*
             * Nullable because corpus-origin rows have no feedback behind them.
             * For community rows it is required, enforced at the model level so
             * no code path can create one without provenance.
             */
            $table->foreignId('feedback_id')
                ->nullable()
                ->constrained('feedback')
                ->nullOnDelete();

            $table->foreignId('submission_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('origin', 16);
            $table->string('type', 16);

            // Already redacted: this is copied from submissions.content_redacted,
            // which RedactorService produced before the row was ever stored.
            $table->text('content');
            $table->char('content_hash', 64);

            // The label a moderator assigned, not the label a user asserted.
            $table->string('label', 16);

            /*
             * Who approved it and when. An unattributed training row cannot be
             * audited, and "who approved this" is the first question to ask
             * when a model starts behaving oddly after a retrain.
             */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            /*
             * Set when the row is exported into a numbered dataset version.
             * Null means approved but not yet part of any training set — the
             * gap between "a human said yes" and "a model saw it", which is
             * deliberately a separate, explicit step.
             */
            $table->string('dataset_version', 32)->nullable();
            $table->timestamp('exported_at')->nullable();

            $table->timestamps();

            // One training row per feedback item; re-approving cannot duplicate.
            $table->unique('feedback_id');

            $table->index(['origin', 'label']);
            $table->index('dataset_version');
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_samples');
    }
};
