<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a retraining row came from.
 *
 * This is the v1/v2 story in Chapter 4: it lets the report state exactly how
 * much of a retraining set was contributed by users versus inherited from the
 * original corpus, rather than asserting it.
 */
enum TrainingSampleOrigin: string
{
    /** Promoted from moderated community feedback. Requires a feedback_id. */
    case Community = 'community';

    /** Imported from an original dataset. No feedback behind it. */
    case Corpus = 'corpus';

    /**
     * Whether rows of this origin must carry approved feedback provenance.
     */
    public function requiresApprovedFeedback(): bool
    {
        return $this === self::Community;
    }
}
