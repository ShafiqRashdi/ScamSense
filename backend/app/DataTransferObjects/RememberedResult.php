<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The visitor's latest verdict, as kept in their session between the POST
 * that produced it and the GET that shows it.
 *
 * The fused VerdictResult is kept whole because part of it is never persisted
 * (the recognised institution, the raw model probability), so the result page
 * could not be rebuilt from the database alone.
 */
final readonly class RememberedResult
{
    public function __construct(
        public VerdictResult $verdict,
        public int $verdictId,
    ) {}
}
