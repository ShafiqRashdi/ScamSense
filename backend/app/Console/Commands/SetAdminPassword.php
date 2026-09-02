<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sets an administrator's password (security review finding 10).
 *
 * ## Why this exists
 *
 * `AdminUserSeeder` deliberately refuses to touch an account that already
 * exists — a seeder that silently reset a live password would be worse than
 * the problem it solved. The consequence was that nothing could change a
 * password at all: there is no self-service change screen, no reset flow, and
 * re-running the seeder only prints "already exists — left unchanged".
 *
 * That left `docs/deployment.md` instructing an impossible action. It tells the
 * operator to rotate the seeded password after first login, because platform
 * build logs capture stdout and a printed password can persist in the deploy
 * history. There was no supported way to carry that out, and on a hosted
 * database "open tinker against production" is not one.
 *
 * ## The password is never printed unless this command generated it
 *
 * A password supplied by the operator is already in their possession, so
 * echoing it back only creates a second copy in the terminal scrollback and,
 * on a platform runner, in the build log. It is read without echo, or taken
 * from configuration, and never written to output or to a log line.
 *
 * `--generate` is the one exception: a generated password has to be shown once
 * or it is lost. That output is worth avoiding on a platform runner, which is
 * exactly why `--from-env` exists.
 */
class SetAdminPassword extends Command
{
    protected $signature = 'scamsense:admin-password
                            {email? : The administrator to update; defaults to the configured address}
                            {--from-env : Use ADMIN_PASSWORD from the environment}
                            {--generate : Generate a strong password and print it once}';

    protected $description = "Set an administrator's password";

    /**
     * Below this a password is not worth setting. Matches the framework's
     * default minimum; the deployment guide asks for considerably more.
     */
    private const MIN_LENGTH = 8;

    private const GENERATED_LENGTH = 24;

    public function handle(): int
    {
        $argument = $this->argument('email');

        /*
         | The positional argument is an address, but it sits exactly where a
         | person reaches for when they want to type a password, and the first
         | real use of this command did precisely that. Refusing with a plain
         | "no user found" is not enough: a password typed here has already
         | been written to shell history in clear text, and the operator needs
         | to be told that rather than left to work it out.
         */
        if (is_string($argument) && $argument !== '' && ! str_contains($argument, '@')) {
            $this->error("\"{$argument}\" is not an email address.");
            $this->newLine();
            $this->warn('This argument selects WHICH administrator to update, not the new password.');
            $this->line('  To set the password from the environment:  --from-env');
            $this->line('  To type it in, hidden:                     (pass nothing)');
            $this->newLine();
            $this->warn('If you meant that to be the password, it is now in your shell history.');
            $this->warn('Choose a different one.');

            return self::FAILURE;
        }

        $email = (string) ($argument ?? config('scamsense.admin.email'));

        if ($email === '') {
            $this->error('No email given and scamsense.admin.email is not configured.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}.");
            $this->line('Run: php artisan db:seed --class=AdminUserSeeder');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        // Reported by address only. The value that was set is never echoed,
        // except by --generate above, which has already printed it.
        $this->info("Password updated for {$email}.");

        if ($this->option('from-env')) {
            $this->line('It now matches ADMIN_PASSWORD in the environment.');
        }

        return self::SUCCESS;
    }

    /**
     * The new password, or null when the input was unusable.
     *
     * The three sources are mutually exclusive in practice but not enforced as
     * such: --generate wins, then --from-env, then an interactive prompt.
     */
    private function resolvePassword(): ?string
    {
        if ($this->option('generate')) {
            $password = Str::password(self::GENERATED_LENGTH);

            $this->warn('Generated password (shown once, not stored anywhere):');
            $this->line("    {$password}");
            $this->newLine();
            $this->warn('If this ran on a hosting platform, assume the build log captured it.');
            $this->warn('Set ADMIN_PASSWORD in the secret store and re-run with --from-env instead.');

            return $password;
        }

        if ($this->option('from-env')) {
            $configured = config('scamsense.admin.password');

            if (! is_string($configured) || $configured === '') {
                $this->error('ADMIN_PASSWORD is not set in the environment.');

                return null;
            }

            return $this->validated($configured);
        }

        // secret() reads without echoing, so the password does not appear in
        // the terminal or in shell history.
        $password = (string) $this->secret('New password');
        $confirm = (string) $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('The two entries did not match.');

            return null;
        }

        return $this->validated($password);
    }

    private function validated(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $this->error('Password must be at least '.self::MIN_LENGTH.' characters.');

            return null;
        }

        return $password;
    }
}
