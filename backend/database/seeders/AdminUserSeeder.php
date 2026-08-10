<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions the single administrator account.
 *
 * AGENTS.md forbids default credentials in the seeder, so there is no
 * `admin@example.com` / `password` pair anywhere in this repository. Instead:
 *
 *   • If ADMIN_EMAIL and ADMIN_PASSWORD are set in .env, those are used.
 *   • Otherwise a cryptographically random password is generated and printed
 *     to the console exactly once. It is never written to a file or a log.
 *
 * Re-running the seeder will not silently reset an existing administrator's
 * password — that would be a quiet account-takeover primitive on a shared box.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('scamsense.admin.email');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("Administrator {$email} already exists — left unchanged.");

            return;
        }

        $configured = config('scamsense.admin.password');
        $password = is_string($configured) && $configured !== ''
            ? $configured
            : Str::password(20);

        User::query()->create([
            'name' => (string) config('scamsense.admin.name'),
            'email' => $email,
            'role' => UserRole::Admin,
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Administrator created: {$email}");

        if (! is_string($configured) || $configured === '') {
            $this->command?->warn('Generated password (shown once, not stored anywhere):');
            $this->command?->line("    {$password}");
        }
    }
}
