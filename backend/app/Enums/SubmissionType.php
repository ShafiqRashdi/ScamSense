<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of artefact the user submitted.
 *
 * The two branches route to different models — DistilBERT for prose, the Random
 * Forest over lexical features for links — so this drives which prediction
 * endpoint DetectionService calls in Phase 5.
 */
enum SubmissionType: string
{
    case Message = 'message';
    case Url = 'url';

    /**
     * The maximum accepted length, from AGENTS.md §3 via config.
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::Message => (int) config('scamsense.limits.message_max_chars'),
            self::Url => (int) config('scamsense.limits.url_max_chars'),
        };
    }
}
