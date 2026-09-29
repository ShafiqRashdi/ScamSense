<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * What the result page needs to connect each reason to the text it came from.
 *
 * `numbers` is aligned with the verdict's indicator list: `numbers[$i]` is the
 * number shown beside indicator $i, or null when its evidence could not be
 * found in the submission and so has nothing to point at.
 */
final readonly class SubmissionExplanation
{
    /**
     * @param  list<MarkedSegment>  $segments
     * @param  list<int|null>  $numbers
     */
    public function __construct(
        public array $segments,
        public array $numbers,
        public ?AddressAnatomy $anatomy,
    ) {}

    public function hasMarks(): bool
    {
        foreach ($this->numbers as $number) {
            if ($number !== null) {
                return true;
            }
        }

        return false;
    }
}
