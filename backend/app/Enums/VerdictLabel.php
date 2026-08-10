<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The classification returned for a submission.
 *
 * `Scam` and `Legitimate` are the two labels the models emit (AGENTS.md §4).
 * `Unknown` is not a model output — it is what a verdict carries when no model
 * ran at all, which happens when the ML service is unavailable and ScamSense
 * degrades to a partial verdict rather than failing (AGENTS.md §2, rule 4).
 * Keeping it in the same column, rather than leaving the label null, means the
 * UI and the admin dashboard never have to special-case a missing value.
 */
enum VerdictLabel: string
{
    case Scam = 'scam';
    case Legitimate = 'legitimate';
    case Unknown = 'unknown';
}
