<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the user pasted in, after redaction.
 *
 * Two rules from AGENTS.md §3 shape this table:
 *
 *   • Nothing raw is stored. RedactorService masks Malaysian phone numbers, IC
 *     numbers, emails and bank account numbers before the row is written, not
 *     after — once raw PII reaches MySQL it is in the backups too.
 *
 *   • `content_hash` is a SHA-256 used for deduplication. It is computed over
 *     the *redacted* text, which is a deliberate departure from the wording in
 *     AGENTS.md §3 ("a hash of the raw input"), for two reasons. First, hashing
 *     the raw string would recreate the very PII exposure redaction exists to
 *     prevent: a short message or a bare phone number is trivially recovered
 *     from its hash by brute force, so the column would become an oracle for
 *     the data we just masked. Second, hashing after redaction is *better* at
 *     the job it is for — the same scam template sent to a thousand people with
 *     a different callback number in each copy collapses to one hash, which is
 *     what campaign-frequency counting in Phase 8 actually wants.
 *     See docs/adr/0002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 16);
            $table->text('content_redacted');

            // SHA-256 hex digest, always 64 characters. Non-unique: repeat
            // submissions of the same scam are a signal worth counting, so the
            // history is kept and the index makes prior-verdict lookup cheap.
            $table->char('content_hash', 64);

            // Hashed, never raw. Rate limiting and abuse detection need to tell
            // IP addresses apart, not identify the people behind them; a hash
            // does the first without the second. Nullable so console-created and
            // seeded rows do not have to invent one.
            $table->char('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->index('content_hash');
            $table->index('ip_hash');
            // Phase 8 charts submission volume over time, and the Phase 9
            // retention purge deletes by age; both scan this pair.
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
