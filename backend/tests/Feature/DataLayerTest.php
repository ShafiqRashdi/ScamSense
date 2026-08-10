<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\UserRole;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\ThreatIntelCache;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Relationships and casts
|--------------------------------------------------------------------------
*/

it('casts submission type to an enum', function (): void {
    $submission = Submission::factory()->url()->create();

    expect($submission->refresh()->type)->toBe(SubmissionType::Url);
});

it('links a submission to its verdicts and indicators', function (): void {
    $submission = Submission::factory()->create();
    $verdict = Verdict::factory()->for($submission)->create();
    Indicator::factory()->count(3)->for($verdict)->create();

    expect($submission->verdicts)->toHaveCount(1)
        ->and($submission->latestVerdict->id)->toBe($verdict->id)
        ->and($verdict->indicators)->toHaveCount(3)
        ->and($verdict->indicators->first()->verdict->id)->toBe($verdict->id);
});

it('returns the most recent verdict as the latest', function (): void {
    $submission = Submission::factory()->create();
    Verdict::factory()->for($submission)->create(['created_at' => now()->subDay()]);
    $newer = Verdict::factory()->for($submission)->create(['created_at' => now()]);

    expect($submission->latestVerdict->id)->toBe($newer->id);
});

it('deletes verdicts, indicators and feedback when a submission is deleted', function (): void {
    // The Phase 9 retention purge deletes submissions by age and relies on this
    // cascading rather than remembering to clean four tables by hand.
    $submission = Submission::factory()->create();
    $verdict = Verdict::factory()->for($submission)->create();
    Indicator::factory()->for($verdict)->create();
    Feedback::factory()->for($verdict)->create();

    $submission->delete();

    expect(Verdict::count())->toBe(0)
        ->and(Indicator::count())->toBe(0)
        ->and(Feedback::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The degraded verdict — AGENTS.md §2 rule 4
|--------------------------------------------------------------------------
*/

it('stores a partial verdict with no model version', function (): void {
    $verdict = Verdict::factory()->partial()->create();

    expect($verdict->is_partial)->toBeTrue()
        ->and($verdict->model_version)->toBeNull()
        ->and($verdict->label)->toBe(VerdictLabel::Unknown)
        ->and($verdict->risk_level)->toBe(RiskLevel::Unknown)
        ->and($verdict->partial_reason)->toBe('ml_service_unavailable')
        ->and($verdict->isFromModel())->toBeFalse();
});

it('reports a complete verdict as coming from a model', function (): void {
    expect(Verdict::factory()->create()->isFromModel())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Deduplication
|--------------------------------------------------------------------------
*/

it('produces the same content hash for identical content', function (): void {
    $a = Submission::factory()->withContent('Tahniah anda menang')->create();
    $b = Submission::factory()->withContent('Tahniah anda menang')->create();

    expect($a->content_hash)->toBe($b->content_hash);
});

it('ignores surrounding whitespace when hashing', function (): void {
    expect(Submission::hashContent('  Tahniah  '))->toBe(Submission::hashContent('Tahniah'));
});

it('allows repeat submissions of the same content', function (): void {
    // content_hash is indexed but NOT unique: repeat reports of one scam are a
    // signal worth counting for the Phase 8 volume chart.
    Submission::factory()->withContent('Sama')->create();
    Submission::factory()->withContent('Sama')->create();

    expect(Submission::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Feedback moderation — the data-poisoning control
|--------------------------------------------------------------------------
*/

it('creates feedback in the pending state', function (): void {
    expect(Feedback::factory()->create()->status)->toBe(FeedbackStatus::Pending);
});

it('defaults feedback status to pending at the database level', function (): void {
    // Asserted against the column default, not the factory, so that a code path
    // which forgets to set a status still lands in the moderation queue.
    $verdict = Verdict::factory()->create();

    $id = DB::table('feedback')->insertGetId([
        'verdict_id' => $verdict->id,
        'agrees' => true,
        'ip_hash' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Feedback::find($id)->status)->toBe(FeedbackStatus::Pending);
});

it('prevents the same IP voting twice on one verdict', function (): void {
    $verdict = Verdict::factory()->create();
    $ip = hash('sha256', '203.0.113.9');

    Feedback::factory()->for($verdict)->create(['ip_hash' => $ip]);

    expect(fn () => Feedback::factory()->for($verdict)->create(['ip_hash' => $ip]))
        ->toThrow(QueryException::class);
});

it('allows the same IP to vote on different verdicts', function (): void {
    $ip = hash('sha256', '203.0.113.9');

    Feedback::factory()->create(['ip_hash' => $ip]);
    Feedback::factory()->create(['ip_hash' => $ip]);

    expect(Feedback::count())->toBe(2);
});

it('scopes the moderation queue to pending items, oldest first', function (): void {
    Feedback::factory()->approved()->create();
    $older = Feedback::factory()->create(['created_at' => now()->subDay()]);
    $newer = Feedback::factory()->create(['created_at' => now()]);

    $queue = Feedback::pending()->get();

    expect($queue)->toHaveCount(2)
        ->and($queue->first()->id)->toBe($older->id)
        ->and($queue->last()->id)->toBe($newer->id);
});

it('records who moderated an item', function (): void {
    $admin = User::factory()->admin()->create();
    $feedback = Feedback::factory()->approved($admin)->create();

    expect($feedback->moderator->id)->toBe($admin->id)
        ->and($feedback->status)->toBe(FeedbackStatus::Approved)
        ->and(Feedback::approved()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Threat intel cache
|--------------------------------------------------------------------------
*/

it('hashes URLs case-insensitively for scheme and host but not path', function (): void {
    expect(ThreatIntelCache::hashUrl('HTTPS://Example.COM/Path'))
        ->toBe(ThreatIntelCache::hashUrl('https://example.com/Path'))
        ->and(ThreatIntelCache::hashUrl('https://example.com/path'))
        ->not->toBe(ThreatIntelCache::hashUrl('https://example.com/PATH'));
});

it('stores one cache entry per provider per url', function (): void {
    $hash = ThreatIntelCache::hashUrl('https://evil.example/x');

    ThreatIntelCache::factory()->create(['url_hash' => $hash]);
    ThreatIntelCache::factory()->virustotal()->create(['url_hash' => $hash]);

    expect(ThreatIntelCache::count())->toBe(2);

    expect(fn () => ThreatIntelCache::factory()->create(['url_hash' => $hash]))
        ->toThrow(QueryException::class);
});

it('excludes expired entries from the unexpired scope', function (): void {
    ThreatIntelCache::factory()->create();
    ThreatIntelCache::factory()->expired()->create([
        'url_hash' => ThreatIntelCache::hashUrl('https://other.example/y'),
    ]);

    expect(ThreatIntelCache::unexpired()->count())->toBe(1);
});

it('casts the provider response to an array', function (): void {
    $entry = ThreatIntelCache::factory()->malicious()->create();

    expect($entry->refresh()->response)->toBeArray()
        ->and($entry->is_malicious)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Roles
|--------------------------------------------------------------------------
*/

it('defaults a user to the least privileged role', function (): void {
    $id = DB::table('users')->insertGetId([
        'name' => 'No Role Given',
        'email' => 'norole@example.test',
        'password' => bcrypt('x'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(User::find($id)->role)->toBe(UserRole::Moderator);
});

it('distinguishes admins from moderators', function (): void {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->canModerate())->toBeTrue()
        ->and($moderator->isAdmin())->toBeFalse()
        ->and($moderator->canModerate())->toBeTrue();
});
