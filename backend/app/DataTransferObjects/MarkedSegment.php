<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * One run of the defanged submission as the result page draws it.
 *
 * `text` is always rendered through Blade's `{{ }}`. A segment only says how
 * to style the run — it never carries markup — so marking evidence cannot
 * open a path for submitted content to reach the page unescaped.
 */
final readonly class MarkedSegment
{
    public function __construct(
        public string $text,
        /** The reason number this run is evidence for, or null for plain text. */
        public ?int $number = null,
        /** True for a redaction mask such as [NO. TEL DILINDUNGI]. */
        public bool $isMask = false,
    ) {}
}
