<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Moderation state of a community feedback item.
 *
 * Community feedback is a data-poisoning vector (AGENTS.md §3): if crowdsourced
 * labels reached a model directly, anyone could teach ScamSense that a real
 * phishing campaign is legitimate simply by submitting enough agreement. So
 * every item starts at `Pending` and only an administrator can move it to
 * `Approved`, and only approved items are ever exported into a new dataset
 * version — which is then trained and evaluated deliberately, not automatically.
 *
 * There is intentionally no state that means "in a live model".
 */
enum FeedbackStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
