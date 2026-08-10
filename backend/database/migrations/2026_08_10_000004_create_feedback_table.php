<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Community reporting — objective O3.
 *
 * A user agrees or disputes a verdict. The row lands at status `pending` and
 * stays there until an administrator approves or rejects it. Only approved
 * items are ever exported into a versioned retraining dataset, which is then
 * trained and evaluated deliberately.
 *
 * This is the data-poisoning control from AGENTS.md §3, and the schema is where
 * it is enforced rather than only in the report: there is no column, status or
 * foreign key anywhere in this database that connects a feedback row to a live
 * model. Poisoning ScamSense would require compromising an administrator
 * account and then a training run, not simply submitting enough agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('verdict_id')
                ->constrained()
                ->cascadeOnDelete();

            // True = "this verdict looks right", false = "I dispute it".
            $table->boolean('agrees');

            // Optional free text. User-supplied, therefore redacted before
            // insert and escaped on render like every other submitted string.
            $table->text('comment')->nullable();

            $table->string('status', 16)->default('pending');

            $table->char('ip_hash', 64);

            $table->foreignId('moderated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 255)->nullable();

            $table->timestamps();

            /*
             * One vote per verdict per IP. AGENTS.md §3 requires feedback be
             * both rate-limited and deduplicated per IP; the rate limit is
             * middleware, but ballot-stuffing a single verdict is a database
             * constraint, because middleware alone would let someone spread
             * duplicate votes across the hourly windows.
             */
            $table->unique(['verdict_id', 'ip_hash']);

            // The moderation queue is "status = pending, oldest first".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
