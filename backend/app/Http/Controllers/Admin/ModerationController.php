<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\VerdictLabel;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\TrainingSample;
use App\Services\Feedback\FeedbackModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The moderation queue (objective O3).
 *
 * Every action here is one item at a time and requires the moderator to state
 * the correct label. There is deliberately **no bulk approve**: a button that
 * approves fifty items at once is a button that gets pressed without reading
 * fifty items, and at that point the review step has become decorative.
 */
class ModerationController extends Controller
{
    public function __construct(private FeedbackModerationService $moderation) {}

    public function index(Request $request): View
    {
        $pending = Feedback::query()
            ->with(['verdict.submission', 'verdict.indicators'])
            ->pending()
            ->paginate(15);

        return view('admin.moderation.index', [
            'pending' => $pending,
            'pendingCount' => Feedback::query()->pending()->count(),
            'approvedCount' => Feedback::query()->approved()->count(),
            'promotedCount' => TrainingSample::query()->fromCommunity()->count(),
            'unexportedCount' => TrainingSample::query()->unexported()->count(),
        ]);
    }

    public function approve(Request $request, Feedback $feedback): RedirectResponse
    {
        $validated = $request->validate([
            // The moderator's label, not the user's opinion. Required, with no
            // default, so approving without deciding is impossible.
            'label' => ['required', 'string', 'in:scam,legitimate'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->moderation->approve(
            $feedback,
            $request->user(),
            VerdictLabel::from($validated['label']),
            $validated['note'] ?? null,
        );

        return back()->with('status', __('Feedback approved and added to the retraining set.'));
    }

    public function reject(Request $request, Feedback $feedback): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->moderation->reject($feedback, $request->user(), $validated['note'] ?? null);

        return back()->with('status', __('Feedback rejected. Nothing was added to the retraining set.'));
    }
}
