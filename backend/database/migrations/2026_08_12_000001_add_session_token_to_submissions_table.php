<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Links a submission to the browser session that created it.
 *
 * ScamSense has no public accounts, so feedback cannot be attributed to a user
 * id. Without some link, anyone could post feedback on any verdict by guessing
 * an id — which would hand an attacker exactly the bulk-labelling primitive the
 * moderation queue exists to prevent.
 *
 * The column stores a SHA-256 of the session's token rather than the token
 * itself. The token is our own random value rather than user input, so this is
 * defence in depth: a database leak should not yield credentials that let
 * someone forge feedback against historical verdicts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->char('session_token_hash', 64)->nullable()->after('ip_hash');
            $table->index('session_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropIndex(['session_token_hash']);
            $table->dropColumn('session_token_hash');
        });
    }
};
