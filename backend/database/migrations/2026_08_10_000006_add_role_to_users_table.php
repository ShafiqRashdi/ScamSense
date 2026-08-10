<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 8 requires an explicit role check on every admin route, so there has to
 * be a role to check.
 *
 * The column defaults to `moderator`, the least-privileged role, rather than to
 * `admin`. A row created by a future code path that forgets to set a role then
 * fails closed instead of silently minting an administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('moderator')->after('email');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
