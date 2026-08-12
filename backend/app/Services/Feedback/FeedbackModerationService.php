<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Enums\TrainingSampleOrigin;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\TrainingSample;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned path from community feedback into the retraining set.
 *
 * "Only" is enforced by TrainingSample's creation invariant rather than by this
 * class being the sole caller — a rule that depends on nobody writing a second
 * path is not a rule. This service is the *convenient* path; the model is the
 * *binding* one.
 *
 * ## Approving is a labelling decision, not agreement
 *
 * `approve()` requires the moderator to state the correct label explicitly. It
 * deliberately does not infer one from whether the user agreed or disputed:
 * a user's opinion is a reason to look, not a label. Inferring it would let the
 * crowd label the dataset by consensus, which is the poisoning path the whole
 * design exists to close.
 */
class FeedbackModerationService
{
    /**
     * Approve feedback and promote it into the retraining set.
     *
     * Atomic: either the feedback is marked approved and the training sample
     * exists, or neither happened. A half-applied approval would leave an
     * approved item with no training row, which a later bulk job might well
     * "helpfully" complete without review.
     */
    public function approve(
        Feedback $feedback,
        User $moderator,
        VerdictLabel $correctLabel,
        ?string $note = null,
    ): TrainingSample {
        return DB::transaction(function () use ($feedback, $moderator, $correctLabel, $note): TrainingSample {
            $feedback->forceFill([
                'status' => FeedbackStatus::Approved,
                'moderated_by' => $moderator->id,
                'moderated_at' => now(),
                'moderation_note' => $note,
            ])->save();

            $submission = $feedback->verdict->submission;

            return TrainingSample::query()->create([
                'feedback_id' => $feedback->id,
                'submission_id' => $submission->id,
                'origin' => TrainingSampleOrigin::Community,
                'type' => $submission->type,
                // Already redacted — RedactorService ran before the submission
                // was ever stored, so nothing raw can reach the dataset.
                'content' => $submission->content_redacted,
                'content_hash' => $submission->content_hash,
                'label' => $correctLabel,
                'approved_by' => $moderator->id,
                'approved_at' => now(),
            ]);
        });
    }

    /**
     * Reject feedback. Nothing is promoted.
     */
    public function reject(Feedback $feedback, User $moderator, ?string $note = null): Feedback
    {
        $feedback->forceFill([
            'status' => FeedbackStatus::Rejected,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
            'moderation_note' => $note,
        ])->save();

        return $feedback;
    }

    /**
     * Export approved, unexported rows into a numbered dataset version.
     *
     * Separate from approval on purpose. Approval says "this row is correct";
     * export says "this row is now part of a dataset a model will see". Keeping
     * them apart means an administrator can review at their own pace without
     * each decision silently changing what the next training run consumes.
     *
     * @return int the number of rows stamped
     */
    public function export(string $datasetVersion): int
    {
        return TrainingSample::query()
            ->unexported()
            ->update(['dataset_version' => $datasetVersion, 'exported_at' => now()]);
    }
}
