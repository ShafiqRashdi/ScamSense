<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 | Security review finding 10. The deployment guide instructs the operator to
 | rotate the seeded administrator password after first login; until this
 | command existed there was no way to do it, because AdminUserSeeder refuses
 | to modify an account that already exists and there is no reset flow.
 */

/**
 * Named distinctly from the `admin()` helper in AdminDashboardTest: Pest loads
 * every test file into one process, so a second `admin()` is a fatal redeclare.
 */
function adminWithPassword(
    string $email = 'admin@scamsense.local',
    string $password = 'original-password',
): User {
    return User::query()->create([
        'name' => 'Test Admin',
        'email' => $email,
        'role' => UserRole::Admin,
        'password' => Hash::make($password),
    ]);
}

it('sets the password from the environment', function (): void {
    $user = adminWithPassword();
    config(['scamsense.admin.password' => 'a-strong-env-password']);

    $this->artisan('scamsense:admin-password', ['--from-env' => true])
        ->assertSuccessful();

    expect(Hash::check('a-strong-env-password', $user->fresh()->password))->toBeTrue();
});

it('defaults to the configured administrator address', function (): void {
    // The regression this exists for: ADMIN_PASSWORD was changed in .env and
    // the running account kept its old hash, because nothing reads that value
    // after the account is first created.
    config([
        'scamsense.admin.email' => 'someone@example.test',
        'scamsense.admin.password' => 'a-strong-env-password',
    ]);
    $user = adminWithPassword('someone@example.test');

    $this->artisan('scamsense:admin-password', ['--from-env' => true])->assertSuccessful();

    expect(Hash::check('a-strong-env-password', $user->fresh()->password))->toBeTrue();
});

it('updates the named user rather than the configured one', function (): void {
    config(['scamsense.admin.password' => 'a-strong-env-password']);
    $first = adminWithPassword('first@example.test');
    $second = adminWithPassword('second@example.test');

    $this->artisan('scamsense:admin-password', [
        'email' => 'second@example.test',
        '--from-env' => true,
    ])->assertSuccessful();

    expect(Hash::check('a-strong-env-password', $second->fresh()->password))->toBeTrue()
        ->and(Hash::check('original-password', $first->fresh()->password))->toBeTrue();
});

it('generates a password when asked', function (): void {
    $user = adminWithPassword();

    $this->artisan('scamsense:admin-password', ['--generate' => true])->assertSuccessful();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeFalse();
});

it('never prints a password it did not generate', function (): void {
    /*
     | A password the operator supplied is already in their possession; echoing
     | it only creates a second copy in terminal scrollback and, on a platform
     | runner, in the build log — which is the exact mechanism finding 10 is
     | about.
     */
    adminWithPassword();
    config(['scamsense.admin.password' => 'a-strong-env-password']);

    $this->artisan('scamsense:admin-password', ['--from-env' => true])
        ->doesntExpectOutputToContain('a-strong-env-password')
        ->assertSuccessful();
});

it('fails when the environment password is not set', function (): void {
    $user = adminWithPassword();
    config(['scamsense.admin.password' => '']);

    $this->artisan('scamsense:admin-password', ['--from-env' => true])->assertFailed();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

it('rejects a password below the minimum length', function (): void {
    $user = adminWithPassword();
    config(['scamsense.admin.password' => 'short']);

    $this->artisan('scamsense:admin-password', ['--from-env' => true])->assertFailed();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

it('fails when no such user exists', function (): void {
    config(['scamsense.admin.password' => 'a-strong-env-password']);

    $this->artisan('scamsense:admin-password', [
        'email' => 'nobody@example.test',
        '--from-env' => true,
    ])->assertFailed();
});

it('rejects a mismatched confirmation at the prompt', function (): void {
    $user = adminWithPassword();

    $this->artisan('scamsense:admin-password')
        ->expectsQuestion('New password', 'a-strong-password')
        ->expectsQuestion('Confirm password', 'a-different-password')
        ->assertFailed();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

it('sets the password entered at the prompt', function (): void {
    $user = adminWithPassword();

    $this->artisan('scamsense:admin-password')
        ->expectsQuestion('New password', 'a-strong-password')
        ->expectsQuestion('Confirm password', 'a-strong-password')
        ->assertSuccessful();

    expect(Hash::check('a-strong-password', $user->fresh()->password))->toBeTrue();
});

it('refuses a positional argument that is not an email, and says why', function (): void {
    /*
     | The argument selects which administrator to update, but it sits exactly
     | where someone reaches to type a password — which is what happened on the
     | command's first real use. A bare "no user found" leaves them to work out
     | that they have just written a password into their shell history.
     */
    $user = adminWithPassword();

    $this->artisan('scamsense:admin-password', ['email' => 'admin_2002'])
        ->expectsOutputToContain('is not an email address')
        ->expectsOutputToContain('now in your shell history')
        ->assertFailed();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});
