<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cached reputation lookups from Google Safe Browsing and VirusTotal.
 *
 * The cache is not an optimisation, it is a functional requirement: VirusTotal's
 * free tier allows four requests a minute, which a live viva demonstration would
 * exhaust in seconds. Entries live for 24 hours (config: services.threat_intel).
 *
 * Keyed on a hash of the URL rather than the URL itself. Submitted URLs are
 * user-controlled and can be arbitrarily long or carry credentials and tracking
 * identifiers in the query string; a fixed-width hash sidesteps index-length
 * limits and keeps that content out of a table people will browse while
 * debugging. The full URL is not needed to answer "have we asked about this
 * one before".
 *
 * Note what this table does NOT enable: ScamSense never fetches a submitted URL
 * (AGENTS.md §2, rule 3). These are third-party reputation answers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threat_intel_cache', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 32);
            $table->char('url_hash', 64);

            // The provider's answer, normalised into a small shape by the
            // client class. JSON because the two providers disagree about
            // everything except the question being asked.
            $table->json('response');

            // Denormalised out of `response` so the common query — "is this
            // known bad" — is an indexed boolean rather than JSON extraction.
            // Nullable: a provider that failed or has no opinion is not a
            // "no", and conflating those would understate risk.
            $table->boolean('is_malicious')->nullable();

            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');

            $table->timestamps();

            // One cached answer per provider per URL; the client upserts on it.
            $table->unique(['provider', 'url_hash']);

            // Phase 8 reports the cache hit rate; Phase 9 purges expired rows.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_intel_cache');
    }
};
