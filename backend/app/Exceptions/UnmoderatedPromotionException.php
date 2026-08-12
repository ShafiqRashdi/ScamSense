<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to put community data into the retraining set
 * without an administrator having approved it.
 *
 * This is a programming error, not a user error, so it is a RuntimeException
 * and is never caught and converted into a friendly message. If it fires in
 * production, a code path exists that bypasses the moderation gate, and that
 * is worth a 500 and an alert rather than a shrug.
 */
class UnmoderatedPromotionException extends RuntimeException
{
    public static function missingFeedback(): self
    {
        return new self(
            'Refusing to create a community training sample with no feedback_id. '
            .'Community rows must be traceable to the moderated feedback they came '
            .'from (AGENTS.md §3, docs/adr/0008).'
        );
    }

    public static function missingApprover(): self
    {
        return new self(
            'Refusing to create a community training sample with no approved_by. '
            .'An unattributed training row cannot be audited.'
        );
    }

    public static function feedbackNotApproved(int $feedbackId): self
    {
        return new self(
            "Refusing to promote feedback #{$feedbackId}: it is not approved. "
            .'Community feedback reaches the retraining set only through explicit '
            .'administrator approval — there is no bulk path, and this is the '
            .'project\'s data-poisoning defence.'
        );
    }

    public static function unknownOrigin(string $origin): self
    {
        return new self("Unknown training sample origin: {$origin}");
    }
}
