<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\DataTransferObjects\VerifiedInstitution;

/**
 * Recognises registrable domains operated by verified Malaysian institutions.
 *
 * This is a **safety mitigation, not a model improvement** (ADR 0007). It exists
 * because the training corpus under-represents legitimate authenticated pages,
 * so the classifier flags real bank login URLs. Every model figure in
 * docs/RESULTS.md is measured with this gate absent, and it must never be
 * described as improving accuracy.
 *
 * ## Why registry facts may gate a verdict when clean threat intel may not
 *
 * A clean Safe Browsing lookup says "not currently reported", which is the
 * default state of every phishing domain registered in the last few hours. It
 * is absence of evidence. Membership here is a positive, externally checkable
 * fact: `.gov.my` may only be registered by an accredited Malaysian government
 * body, and the bank domains are published on Bank Negara Malaysia's register
 * of licensed institutions. See ADR 0007 for the full argument.
 *
 * ## The matching rule, and why it is strict
 *
 * Matching is on the **registrable domain only** — exact equality, never
 * substring or suffix containment against an arbitrary host. Loosening this
 * would convert the mitigation into a bypass: an attacker registering
 * `maybank2u.com.my.secure-verify.xyz` has the registrable domain
 * `secure-verify.xyz`, and must not match. A test asserts precisely that.
 *
 * ## Residual risk
 *
 * A match caps every path under the domain, so open redirects and compromised
 * subpages on a verified domain are capped too. Threat-intel escalation runs
 * before the gate and covers the reported cases; the window before a report
 * exists is an accepted residual risk, documented in ADR 0007.
 */
class VerifiedDomainRegistry
{
    /** @var array<string, VerifiedInstitution> keyed by registrable domain */
    private array $byDomain = [];

    /** @var array<string, array<string, string>> keyed by restricted suffix */
    private array $bySuffix = [];

    /**
     * @param  array<string, array<string, mixed>>  $institutions
     * @param  array<string, array<string, string>>  $suffixes
     */
    public function __construct(array $institutions = [], array $suffixes = [])
    {
        foreach ($institutions as $institution) {
            foreach ((array) ($institution['domains'] ?? []) as $domain) {
                $this->byDomain[strtolower((string) $domain)] = new VerifiedInstitution(
                    domain: strtolower((string) $domain),
                    name: (string) $institution['name'],
                    nameMs: isset($institution['name_ms']) ? (string) $institution['name_ms'] : null,
                    authority: (string) $institution['authority'],
                    reference: (string) $institution['reference'],
                    verifiedOn: (string) $institution['verified_on'],
                );
            }
        }

        foreach ($suffixes as $suffix => $meta) {
            $this->bySuffix[strtolower((string) $suffix)] = $meta;
        }
    }

    public static function fromConfig(): self
    {
        /** @var array<string, array<string, mixed>> $institutions */
        $institutions = config('verified_institutions.institutions', []);
        /** @var array<string, array<string, string>> $suffixes */
        $suffixes = config('verified_institutions.suffixes', []);

        return new self($institutions, $suffixes);
    }

    /**
     * The institution behind a URL's registrable domain, or null.
     *
     * Returns the institution rather than a boolean so the caller can name it
     * to the user — "this is the real Maybank website" is far more reassuring,
     * and far better teaching, than "this domain is verified".
     */
    public function match(string $url): ?VerifiedInstitution
    {
        $host = $this->hostOf($url);

        if ($host === null) {
            return null;
        }

        $registrable = $this->registrableDomain($host);

        if ($registrable === null) {
            return null;
        }

        if (isset($this->byDomain[$registrable])) {
            return $this->byDomain[$registrable];
        }

        foreach ($this->bySuffix as $suffix => $meta) {
            // Exact equality, or ends with ".{suffix}". The leading dot is
            // essential: without it "notgov.my" would match the "gov.my"
            // suffix, which is precisely the bypass this must not permit.
            if ($registrable === $suffix || str_ends_with($registrable, '.'.$suffix)) {
                return new VerifiedInstitution(
                    domain: $registrable,
                    name: (string) $meta['name'],
                    nameMs: isset($meta['name_ms']) ? (string) $meta['name_ms'] : null,
                    authority: (string) $meta['authority'],
                    reference: (string) $meta['reference'],
                    verifiedOn: (string) $meta['verified_on'],
                    bySuffix: true,
                );
            }
        }

        return null;
    }

    public function isVerified(string $url): bool
    {
        return $this->match($url) instanceof VerifiedInstitution;
    }

    private function hostOf(string $url): ?string
    {
        $candidate = trim($url);

        if (! str_contains($candidate, '://')) {
            $candidate = 'http://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Reduce a host to the domain someone actually registered.
     *
     * Mirrors the logic in the ML service's url_features module, including the
     * Malaysian two-part suffixes: without them `maybank2u.com.my` would reduce
     * to `com.my` and every Malaysian domain would collapse together.
     */
    private function registrableDomain(string $host): ?string
    {
        $labels = explode('.', $host);
        $count = count($labels);

        if ($count < 2) {
            return null;
        }

        /** @var list<string> $multiPart */
        $multiPart = config('scamsense.multi_part_suffixes', []);

        $lastTwo = implode('.', array_slice($labels, -2));

        if (in_array($lastTwo, $multiPart, true)) {
            return $count >= 3 ? implode('.', array_slice($labels, -3)) : $lastTwo;
        }

        return $lastTwo;
    }
}
