<?php

declare(strict_types=1);

use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\ThreatIntelCache;
use App\Models\TrainingSample;
use App\Models\User;
use App\Models\Verdict;
use App\Services\Feedback\FeedbackModerationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Finding 3 — security headers
|--------------------------------------------------------------------------
*/

it('sets the security headers on a public page', function (string $header, string $expected): void {
    $response = $this->get('/');

    expect($response->headers->get($header))->toContain($expected);
})->with([
    'CSP' => ['Content-Security-Policy', "default-src 'self'"],
    'nosniff' => ['X-Content-Type-Options', 'nosniff'],
    'frame options' => ['X-Frame-Options', 'DENY'],
    'referrer' => ['Referrer-Policy', 'no-referrer'],
    'permissions' => ['Permissions-Policy', 'camera=()'],
]);

it('forbids framing, so a verdict cannot be embedded in an attacker page', function (): void {
    // A verdict page framed inside an attacker's site is a convincing way to
    // fake a "safe" result to a user.
    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'none'")
        ->and($csp)->toContain("form-action 'self'");
});

it('sets the headers on the admin area too', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not send HSTS over plain HTTP', function (): void {
    // Pinning a browser to https on localhost for a year is a genuinely
    // annoying way to break your own development machine.
    expect($this->get('/')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Finding 9 — the single {!! !!} can only ever receive enum-derived values
|--------------------------------------------------------------------------
*/

it('renders an icon unaffected by submitted content', function (): void {
    /*
     * The pin. If someone later routes user input into iconSvg(), or swaps the
     * unescaped expression for something user-derived, the payload below starts
     * appearing verbatim in the response and this fails.
     */
    Http::fake(['*/predict/*' => Http::response([
        'request_id' => 't', 'label' => 'scam', 'confidence' => 0.9,
        'indicators' => [], 'model_version' => 'v', 'inference_ms' => 1,
    ])]);

    $payload = '</span><script>alert(1)</script><span>';

    $html = $this->post('/verify', ['type' => 'message', 'content' => $payload])
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        // The icon is still there, and still exactly the enum's SVG.
        ->and($html)->toContain('<svg xmlns="http://www.w3.org/2000/svg"');
});

it('produces an icon for every risk level with no interpolation', function (): void {
    foreach (RiskLevel::cases() as $level) {
        $svg = $level->iconSvg();

        expect($svg)->toStartWith('<svg ')
            ->and($svg)->toEndWith('</svg>')
            // No template placeholder or concatenation point survives.
            ->and($svg)->not->toContain('{')
            ->and($svg)->not->toContain('$');
    }
});

/*
|--------------------------------------------------------------------------
| Finding 5 — retention purge
|--------------------------------------------------------------------------
*/

it('deletes submissions past the retention period', function (): void {
    Submission::factory()->create(['created_at' => now()->subDays(200)]);
    Submission::factory()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('scamsense:purge')->assertSuccessful();

    expect(Submission::count())->toBe(1);
});

it('cascades to verdicts, indicators and feedback', function (): void {
    $submission = Submission::factory()->create(['created_at' => now()->subDays(200)]);
    $verdict = Verdict::factory()->for($submission)->create();
    Indicator::factory()->for($verdict)->create();
    Feedback::factory()->for($verdict)->create();

    $this->artisan('scamsense:purge')->assertSuccessful();

    expect(Submission::count())->toBe(0)
        ->and(Verdict::count())->toBe(0)
        ->and(Indicator::count())->toBe(0)
        ->and(Feedback::count())->toBe(0);
});

it('never deletes a submission backing an approved training sample', function (): void {
    /*
     * A moderator looked at that row and decided it belongs in a retraining
     * set. Deleting it silently would undo a human decision and shrink a
     * dataset a model was trained on, breaking reproducibility.
     */
    $feedback = Feedback::factory()->create();
    $feedback->verdict->submission->forceFill(['created_at' => now()->subDays(500)])->save();

    app(FeedbackModerationService::class)
        ->approve($feedback, User::factory()->admin()->create(), VerdictLabel::Scam);

    $this->artisan('scamsense:purge')->assertSuccessful();

    expect(Submission::count())->toBe(1)
        ->and(TrainingSample::count())->toBe(1);
});

it('clears expired threat-intel cache entries but keeps fresh ones', function (): void {
    ThreatIntelCache::factory()->create();
    ThreatIntelCache::factory()->expired()->create([
        'url_hash' => ThreatIntelCache::hashUrl('https://old.example/x'),
    ]);

    $this->artisan('scamsense:purge')->assertSuccessful();

    expect(ThreatIntelCache::count())->toBe(1);
});

it('deletes nothing on a dry run', function (): void {
    Submission::factory()->create(['created_at' => now()->subDays(200)]);

    $this->artisan('scamsense:purge --dry-run')->assertSuccessful();

    expect(Submission::count())->toBe(1);
});

it('honours an overridden retention period', function (): void {
    Submission::factory()->create(['created_at' => now()->subDays(5)]);

    $this->artisan('scamsense:purge --days=1')->assertSuccessful();

    expect(Submission::count())->toBe(0);
});

it('refuses a nonsensical retention period', function (): void {
    $this->artisan('scamsense:purge --days=0')->assertFailed();
});

it('is scheduled, not merely available', function (): void {
    // A command nobody invokes deletes nothing, and the privacy notice would
    // still be a promise the system does not keep.
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->filter(fn (string $command): bool => str_contains($command, 'scamsense:purge'));

    expect($events)->not->toBeEmpty();
});

it('states the retention period on the submission form', function (): void {
    // The promise and the behaviour have to match, and they are driven by the
    // same config value so they cannot drift.
    $days = config('scamsense.retention.submission_days');

    $this->get('/')->assertOk()->assertSee("for {$days} days");
});
