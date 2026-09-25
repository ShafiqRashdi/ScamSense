<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeedbackStatus;
use App\Http\Requests\SubmitFeedbackRequest;
use App\Models\Feedback;
use App\Services\RedactorService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

/**
 * Public community feedback (objective O3).
 *
 * Everything this controller creates lands at status `pending` and stays there
 * until an administrator reviews it. There is no path from here to the
 * retraining set — see TrainingSample and ADR 0008.
 */
class FeedbackController extends Controller
{
    public function __construct(private RedactorService $redactor) {}

    public function store(SubmitFeedbackRequest $request): RedirectResponse
    {
        try {
            Feedback::query()->create([
                'verdict_id' => $request->verdict()->id,
                'agrees' => $request->agrees(),
                // User-supplied free text: redacted on the way in, like the
                // submission itself, so PII cannot enter through the comment
                // field having been kept out of the message field.
                'comment' => $request->comment() === null
                    ? null
                    : $this->redactor->redactToString($request->comment()),
                'status' => FeedbackStatus::Pending,
                'ip_hash' => hash('sha256', (string) $request->ip()),
            ]);
        } catch (QueryException $exception) {
            // The unique(verdict_id, ip_hash) constraint. Someone voting twice
            // on the same verdict is not an error worth showing — the intent is
            // already recorded, so treat it as success and move on.
            if (! str_contains($exception->getMessage(), 'Duplicate entry')) {
                throw $exception;
            }
        }

        return back()->with('feedback_received', true);
    }
}
