<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The institution behind a verified domain, and the evidence for it.
 *
 * `reference` and `verifiedOn` are carried through rather than left in config
 * because ADR 0007 claims these entries are third-party checkable. That claim
 * is only true if the citation travels with the fact — an allowlist entry
 * nobody can trace is an assertion, not evidence.
 */
final readonly class VerifiedInstitution
{
    public function __construct(
        public string $domain,
        public string $name,
        public ?string $nameMs,
        public string $authority,
        public string $reference,
        public string $verifiedOn,
        /** True when matched by restricted suffix rather than an exact domain. */
        public bool $bySuffix = false,
    ) {}

    /**
     * The display name for the current locale.
     *
     * Falls back to the English name, which for an institution is usually the
     * same string anyway — "Maybank" is not translated.
     */
    public function displayName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ms' && $this->nameMs !== null ? $this->nameMs : $this->name;
    }
}
