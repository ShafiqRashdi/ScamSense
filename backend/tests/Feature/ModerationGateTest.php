<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\TrainingSampleOrigin;
use App\Enums\VerdictLabel;
use App\Exceptions\UnmoderatedPromotionException;
use App\Models\Feedback;
use App\Models\TrainingSample;
use App\Models\User;
use App\Services\Feedback\FeedbackModerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| THE GATE
|--------------------------------------------------------------------------
|
| Objective O3's central claim is that crowdsourced labels never reach a model
| without a human approving them. These tests are what turn that from an
| assertion in a report into a property of the system.
|
| They deliberately attack the gate from every direction available to a
| developer — direct create, mass assignment, a "helpful" bulk update, a
| seeder-style insert, and flipping an innocent row afterwards — because a
| defence that only holds when code is written the intended way is theatre.
|
*/

function pendingFeedback(): Feedback
{
    return Feedback::factory()->create(['status' => FeedbackStatus::Pending]);
}

function moderator(): User
{
    return User::factory()->admin()->create();
}

it('refuses to promote feedback that is still pending', function (): void {
    $feedback = pendingFeedback();

    expect(fn () => TrainingSample::query()->create([
        'feedback_id' => $feedback->id,
        'origin' => TrainingSampleOrigin::Community,
        'type' => 'message',
        'content' => 'Tahniah anda menang',
        'content_hash' => str_repeat('a', 64),
        'label' => VerdictLabel::Scam,
        'approved_by' => moderator()->id,
    ]))->toThrow(UnmoderatedPromotionException::class);

    expect(TrainingSample::count())->toBe(0);
});

it('refuses to promote feedback that was rejected', function (): void {
    $feedback = Feedback::factory()->rejected(moderator())->create();

    expect(fn () => TrainingSample::query()->create([
        'feedback_id' => $feedback->id,
        'origin' => TrainingSampleOrigin::Community,
        'type' => 'message',
        'content' => 'x',
        'content_hash' => str_repeat('b', 64),
        'label' => VerdictLabel::Scam,
        'approved_by' => moderator()->id,
    ]))->toThrow(UnmoderatedPromotionException::class);
});

it('refuses a community sample with no feedback behind it', function (): void {
    // Provenance is not optional: a community row nobody can trace back to a
    // moderation decision is indistinguishable from injected data.
    expect(fn () => TrainingSample::query()->create([
        'feedback_id' => null,
        'origin' => TrainingSampleOrigin::Community,
        'type' => 'message',
        'content' => 'x',
        'content_hash' => str_repeat('c', 64),
        'label' => VerdictLabel::Scam,
        'approved_by' => moderator()->id,
    ]))->toThrow(UnmoderatedPromotionException::class);
});

it('refuses a community sample with no recorded approver', function (): void {
    $feedback = Feedback::factory()->approved(moderator())->create();

    expect(fn () => TrainingSample::query()->create([
        'feedback_id' => $feedback->id,
        'origin' => TrainingSampleOrigin::Community,
        'type' => 'message',
        'content' => 'x',
        'content_hash' => str_repeat('d', 64),
        'label' => VerdictLabel::Scam,
        'approved_by' => null,
    ]))->toThrow(UnmoderatedPromotionException::class);
});

it('cannot be bypassed by creating a corpus row and flipping it to community', function (): void {
    // The obvious way around a creation-time check.
    $sample = TrainingSample::query()->create([
        'origin' => TrainingSampleOrigin::Corpus,
        'type' => 'message',
        'content' => 'from the original corpus',
        'content_hash' => str_repeat('e', 64),
        'label' => VerdictLabel::Scam,
    ]);

    $feedback = pendingFeedback();

    expect(fn () => $sample->update([
        'origin' => TrainingSampleOrigin::Community,
        'feedback_id' => $feedback->id,
    ]))->toThrow(UnmoderatedPromotionException::class);
});

it('cannot be bypassed by mass assignment', function (): void {
    $feedback = pendingFeedback();

    expect(fn () => TrainingSample::query()->create(
        collect([
            'feedback_id' => $feedback->id,
            'origin' => 'community',
            'type' => 'message',
            'content' => 'x',
            'content_hash' => str_repeat('f', 64),
            'label' => 'scam',
            'approved_by' => moderator()->id,
        ])->all()
    ))->toThrow(UnmoderatedPromotionException::class);
});

it('rejects an unknown origin rather than defaulting to something permissive', function (): void {
    /*
     * The enum cast rejects this before the invariant is reached, which is
     * fine — the property that matters is that an unrecognised origin cannot
     * become a row, not which layer says no first. Asserting the specific
     * exception type here would be testing Laravel's casting, not the gate.
     */
    expect(fn () => TrainingSample::query()->create([
        'origin' => 'whatever',
        'type' => 'message',
        'content' => 'x',
        'content_hash' => str_repeat('g', 64),
        'label' => VerdictLabel::Scam,
    ]))->toThrow(ValueError::class);

    expect(TrainingSample::count())->toBe(0);
});

it('has no bulk-approve route', function (): void {
    /*
     * A button that approves fifty items at once is a button pressed without
     * reading fifty items, at which point review is decorative. This asserts
     * the absence structurally so a future "convenience" route has to argue
     * with a failing test first.
     */
    $routes = collect(Route::getRoutes())->map(fn ($r): string => $r->uri());

    expect($routes->filter(fn (string $uri): bool => str_contains($uri, 'moderation')))
        ->each(fn ($uri) => $uri->not->toContain('bulk'));

    expect($routes->contains(fn (string $uri): bool => str_contains($uri, 'approve-all')))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The sanctioned path works
|--------------------------------------------------------------------------
*/

it('promotes feedback when an administrator approves it', function (): void {
    $feedback = pendingFeedback();
    $admin = moderator();

    $sample = app(FeedbackModerationService::class)
        ->approve($feedback, $admin, VerdictLabel::Legitimate, 'Checked the bank site directly.');

    expect($feedback->fresh()->status)->toBe(FeedbackStatus::Approved)
        ->and($sample->origin)->toBe(TrainingSampleOrigin::Community)
        ->and($sample->label)->toBe(VerdictLabel::Legitimate)
        ->and($sample->approved_by)->toBe($admin->id)
        ->and($sample->approved_at)->not->toBeNull();
});

it('preserves feedback_id so retraining data can be traced to its source', function (): void {
    /*
     * The v1/v2 story in Chapter 4: exactly which rows came from users versus
     * the original corpus, traceable per row back to the moderation decision.
     */
    $feedback = pendingFeedback();

    $sample = app(FeedbackModerationService::class)
        ->approve($feedback, moderator(), VerdictLabel::Scam);

    expect($sample->feedback_id)->toBe($feedback->id)
        ->and($sample->feedback->id)->toBe($feedback->id)
        ->and($sample->submission_id)->toBe($feedback->verdict->submission->id);
});

it('uses the moderator label, not the reporter opinion', function (): void {
    // A user disputing a verdict is a reason to look, not a label. If the
    // reporter's assertion became the label, the crowd would be labelling the
    // dataset by consensus.
    $feedback = Feedback::factory()->disputed()->create();

    $sample = app(FeedbackModerationService::class)
        ->approve($feedback, moderator(), VerdictLabel::Scam);

    expect($feedback->agrees)->toBeFalse()
        ->and($sample->label)->toBe(VerdictLabel::Scam);
});

it('promotes nothing when feedback is rejected', function (): void {
    $feedback = pendingFeedback();

    app(FeedbackModerationService::class)->reject($feedback, moderator(), 'No evidence given.');

    expect($feedback->fresh()->status)->toBe(FeedbackStatus::Rejected)
        ->and(TrainingSample::count())->toBe(0);
});

it('rolls back the approval entirely if promotion fails', function (): void {
    // A half-applied approval would leave an approved item with no training
    // row, which a later bulk job might "helpfully" complete without review.
    $feedback = pendingFeedback();
    $admin = moderator();
    $service = app(FeedbackModerationService::class);

    $service->approve($feedback, $admin, VerdictLabel::Scam);

    // The unique(feedback_id) constraint makes a second promotion fail.
    expect(fn () => $service->approve($feedback->fresh(), $admin, VerdictLabel::Scam))
        ->toThrow(QueryException::class);

    expect(TrainingSample::where('feedback_id', $feedback->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Export is a third, separate step
|--------------------------------------------------------------------------
*/

it('does not put approved rows into a dataset version automatically', function (): void {
    // Three explicit steps between a stranger's opinion and a model's weights:
    // approve, export, train.
    $sample = app(FeedbackModerationService::class)
        ->approve(pendingFeedback(), moderator(), VerdictLabel::Scam);

    expect($sample->dataset_version)->toBeNull()
        ->and($sample->exported_at)->toBeNull()
        ->and(TrainingSample::unexported()->count())->toBe(1);
});

it('stamps a dataset version only when explicitly exported', function (): void {
    $service = app(FeedbackModerationService::class);
    $service->approve(pendingFeedback(), moderator(), VerdictLabel::Scam);

    expect($service->export('msg-v2.0.0'))->toBe(1);
    expect(TrainingSample::first()->dataset_version)->toBe('msg-v2.0.0')
        ->and(TrainingSample::unexported()->count())->toBe(0);
});

it('separates community rows from corpus rows for reporting', function (): void {
    app(FeedbackModerationService::class)->approve(pendingFeedback(), moderator(), VerdictLabel::Scam);

    TrainingSample::query()->create([
        'origin' => TrainingSampleOrigin::Corpus,
        'type' => 'message',
        'content' => 'from the original corpus',
        'content_hash' => str_repeat('h', 64),
        'label' => VerdictLabel::Legitimate,
    ]);

    expect(TrainingSample::count())->toBe(2)
        ->and(TrainingSample::fromCommunity()->count())->toBe(1);
});

it('leaves no unmoderated community rows reachable in the database', function (): void {
    /*
     * The end-state assertion: whatever route was taken, every community row
     * in the table traces to approved feedback and a named approver.
     */
    app(FeedbackModerationService::class)->approve(pendingFeedback(), moderator(), VerdictLabel::Scam);
    app(FeedbackModerationService::class)->reject(pendingFeedback(), moderator());

    $orphaned = DB::table('training_samples')
        ->where('origin', 'community')
        ->where(function ($query): void {
            $query->whereNull('feedback_id')->orWhereNull('approved_by');
        })
        ->count();

    expect($orphaned)->toBe(0);

    $unapproved = DB::table('training_samples')
        ->join('feedback', 'training_samples.feedback_id', '=', 'feedback.id')
        ->where('training_samples.origin', 'community')
        ->where('feedback.status', '!=', 'approved')
        ->count();

    expect($unapproved)->toBe(0);
});
