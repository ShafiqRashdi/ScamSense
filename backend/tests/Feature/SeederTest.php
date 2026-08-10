<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\User;
use App\Models\Verdict;
use App\Services\RedactorService;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\SampleSubmissionSeeder;

it('seeds an administrator with a generated password and no default credentials', function (): void {
    config()->set('scamsense.admin.password', null);

    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', config('scamsense.admin.email'))->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(UserRole::Admin);

    // The two credential pairs a grader would try first must not work.
    expect(Auth::attempt(['email' => $admin->email, 'password' => 'password']))->toBeFalse()
        ->and(Auth::attempt(['email' => $admin->email, 'password' => 'admin']))->toBeFalse();
});

it('does not reset an existing administrator when re-run', function (): void {
    config()->set('scamsense.admin.password', 'known-password-for-this-test');

    $this->seed(AdminUserSeeder::class);
    $original = User::query()->where('email', config('scamsense.admin.email'))->first();

    config()->set('scamsense.admin.password', 'a-different-password');
    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', $original->email)->first()->password)
        ->toBe($original->password)
        ->and(User::query()->where('email', $original->email)->count())->toBe(1);
});

it('seeds Malaysian sample data across both submission types', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    expect(Submission::count())->toBeGreaterThan(10)
        ->and(Submission::where('type', 'message')->count())->toBeGreaterThan(0)
        ->and(Submission::where('type', 'url')->count())->toBeGreaterThan(0)
        ->and(Verdict::where('label', VerdictLabel::Scam)->count())->toBeGreaterThan(0)
        ->and(Verdict::where('label', VerdictLabel::Legitimate)->count())->toBeGreaterThan(0)
        ->and(Indicator::count())->toBeGreaterThan(20);
});

it('seeds a partial verdict so the degraded UI path has data', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    $partial = Verdict::query()->where('is_partial', true)->first();

    expect($partial)->not->toBeNull()
        ->and($partial->model_version)->toBeNull()
        ->and($partial->label)->toBe(VerdictLabel::Unknown);
});

it('seeds a moderation queue that is entirely pending', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    expect(Feedback::count())->toBeGreaterThan(0)
        ->and(Feedback::pending()->count())->toBe(Feedback::count())
        ->and(Feedback::approved()->count())->toBe(0);
});

/*
 * The important one. The seeder's samples contain real-looking PII, and the
 * seeder runs them through RedactorService on the way in. If someone later
 * pre-masks the samples or bypasses the redactor, this fails.
 */
it('persists no unredacted PII from the sample data', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    $stored = Submission::pluck('content_redacted')->implode("\n")
        ."\n".Indicator::pluck('evidence')->implode("\n");

    expect($stored)
        ->not->toContain('012-345 6789')
        ->not->toContain('019-876 5432')
        ->not->toContain('03-2145 6789')
        ->not->toContain('1234567890');

    expect($stored)->toContain(RedactorService::MASK_PHONE);
});

it('keeps the scam signal intact after redacting the sample data', function (): void {
    // Redaction must not eat the phishing domains — they are the feature the
    // URL model is trained on.
    $this->seed(SampleSubmissionSeeder::class);

    $stored = Submission::pluck('content_redacted')->implode("\n");

    expect($stored)
        ->toContain('maybank2u-verify.xyz')
        ->toContain('poslaju-cukai.top')
        ->toContain('RM5,000')
        ->toContain('24 jam');
});

it('marks seeded verdicts as seed data rather than model output', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    $versions = Verdict::query()
        ->whereNotNull('model_version')
        ->distinct()
        ->pluck('model_version');

    // Nothing in the seeded data may claim to have come from a real model —
    // otherwise a Chapter 4 screenshot of seeded data would be indistinguishable
    // from a measured result (AGENTS.md §7).
    expect($versions->all())->toBe(['seed-data-not-a-model']);
});
