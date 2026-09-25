<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\UserRole;
use App\Models\Feedback;
use App\Models\Submission;
use App\Models\TrainingSample;
use App\Models\User;
use App\Models\Verdict;
use App\Services\Feedback\SessionTokenService;
use Illuminate\Support\Facades\Http;

function fakeMlOk(): void
{
    Http::fake([
        '*/predict/*' => Http::response([
            'request_id' => 't',
            'label' => 'scam',
            'confidence' => 0.93,
            'indicators' => [],
            'model_version' => 'rf-url-v1.2.0-no-https',
            'inference_ms' => 10,
        ]),
    ]);
}

/**
 * Run a real submission so the session token is issued exactly as it is in
 * production, then return the verdict it produced.
 */
function submitAndGetVerdict(string $content = 'http://lhdn-refund.site/claim'): Verdict
{
    fakeMlOk();
    test()->post('/verify', ['type' => 'url', 'content' => $content])->assertOk();

    return Verdict::latest('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Session scoping
|--------------------------------------------------------------------------
*/

it('accepts feedback on a verdict from the same session', function (): void {
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 0])
        ->assertRedirect();

    expect(Feedback::count())->toBe(1);
});

it('refuses feedback on a verdict belonging to another session', function (): void {
    /*
     * Without this, feedback would be postable against any verdict id — which
     * is exactly the bulk-labelling primitive the moderation queue exists to
     * deny. Walking ids would let one person flood the queue with assertions
     * about verdicts they never saw.
     */
    $verdict = submitAndGetVerdict();

    // A different visitor: fresh session, so a different token.
    $this->flushSession();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 0])
        ->assertForbidden();

    expect(Feedback::count())->toBe(0);
});

it('refuses feedback on a submission with no session token at all', function (): void {
    // Seeded and console-created rows have none; they must not be open targets.
    $submission = Submission::factory()->create(['session_token_hash' => null]);
    $verdict = Verdict::factory()->for($submission)->create();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 1])
        ->assertForbidden();
});

it('refuses feedback on a verdict that does not exist', function (): void {
    $this->post('/feedback', ['verdict_id' => 999999, 'agrees' => 1])->assertForbidden();
});

it('issues a stable token for the life of a session', function (): void {
    $service = app(SessionTokenService::class);

    expect($service->token())->toBe($service->token())
        ->and($service->hash())->toHaveLength(64);
});

/*
|--------------------------------------------------------------------------
| Everything lands pending
|--------------------------------------------------------------------------
*/

it('records feedback as pending, never approved', function (): void {
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 0]);

    expect(Feedback::first()->status)->toBe(FeedbackStatus::Pending)
        ->and(Feedback::first()->moderated_by)->toBeNull();
});

it('creates no training sample when feedback is submitted', function (): void {
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 0]);

    expect(TrainingSample::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Validation and abuse
|--------------------------------------------------------------------------
*/

it('validates the feedback payload', function (array $payload): void {
    $verdict = submitAndGetVerdict();
    $payload['verdict_id'] = $verdict->id;

    $this->post('/feedback', $payload)->assertSessionHasErrors();
})->with([
    'missing agrees' => [[]],
    'non-boolean agrees' => [['agrees' => 'maybe']],
    'comment too long' => [['agrees' => 1, 'comment' => str_repeat('x', 1001)]],
]);

it('redacts PII from a feedback comment', function (): void {
    // PII must not enter through the comment field having been kept out of the
    // message field.
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', [
        'verdict_id' => $verdict->id,
        'agrees' => 0,
        'comment' => 'Ini betul, hubungi saya di 012-345 6789',
    ]);

    expect(Feedback::first()->comment)
        ->not->toContain('012-345 6789')
        ->toContain('DILINDUNGI');
});

it('escapes HTML in a comment when the moderator views it', function (): void {
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', [
        'verdict_id' => $verdict->id,
        'agrees' => 0,
        'comment' => '<script>alert(1)</script>',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/moderation')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('does not create a duplicate when the same session votes twice', function (): void {
    $verdict = submitAndGetVerdict();

    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 1])->assertRedirect();
    $this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 0])->assertRedirect();

    expect(Feedback::count())->toBe(1);
});

it('rate limits the feedback endpoint', function (): void {
    /*
     * Unauthenticated write path: same abuse surface as submission. Both a
     * per-minute and a per-hour limit apply, and the per-minute one is tighter
     * than verification's because there is no legitimate reason to leave
     * feedback in a burst.
     */
    $verdict = submitAndGetVerdict();

    $sawThrottle = false;
    for ($i = 0; $i < 12; $i++) {
        if ($this->post('/feedback', ['verdict_id' => $verdict->id, 'agrees' => 1])->status() === 429) {
            $sawThrottle = true;
            break;
        }
    }

    expect($sawThrottle)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The admin area
|--------------------------------------------------------------------------
*/

it('keeps the moderation queue behind authentication', function (): void {
    $this->get('/admin/moderation')->assertRedirect('/login');
});

it('checks the role, not merely that someone is signed in', function (): void {
    /*
     * `auth` says somebody is signed in; the role check says they are allowed
     * to be here. Collapsing them is how a future public-account feature would
     * silently grant moderation rights to everyone.
     */
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer)->get('/admin/moderation')->assertForbidden();
});

it('lets a moderator see the queue', function (): void {
    Feedback::factory()->count(3)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/moderation')
        ->assertOk()
        ->assertSee('Moderation queue');
});

it('requires an explicit label to approve', function (): void {
    // Approving without deciding must be impossible.
    $feedback = Feedback::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post("/admin/moderation/{$feedback->id}/approve", [])
        ->assertSessionHasErrors('label');

    expect(TrainingSample::count())->toBe(0);
});

it('rejects an invalid label', function (): void {
    $feedback = Feedback::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post("/admin/moderation/{$feedback->id}/approve", ['label' => 'whatever'])
        ->assertSessionHasErrors('label');
});

it('promotes through the admin route with provenance intact', function (): void {
    $feedback = Feedback::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post("/admin/moderation/{$feedback->id}/approve", ['label' => 'scam'])
        ->assertRedirect();

    $sample = TrainingSample::firstOrFail();

    expect($sample->feedback_id)->toBe($feedback->id)
        ->and($sample->approved_by)->toBe($admin->id)
        ->and($sample->origin->value)->toBe('community');
});

it('does not let an anonymous visitor approve anything', function (): void {
    $feedback = Feedback::factory()->create();

    $this->post("/admin/moderation/{$feedback->id}/approve", ['label' => 'scam'])
        ->assertRedirect('/login');

    expect(TrainingSample::count())->toBe(0);
});
