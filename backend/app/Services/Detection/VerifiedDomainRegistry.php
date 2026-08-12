<?php

declare(strict_types=1);

namespace App\Services\Detection;

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
 */
class VerifiedDomainRegistry
{
    /**
     * @param  list<string>  $suffixes  Restricted-registration suffixes
     * @param  list<string>  $domains  Exact registrable domains
     */
    public function __construct(
        private array $suffixes = [],
        private array $domains = [],
    ) {
        $this->suffixes = array_map(strtolower(...), $suffixes);
        $this->domains = array_map(strtolower(...), $domains);
    }

    public static function fromConfig(): self
    {
        /** @var array{suffixes: list<string>, domains: list<string>} $config */
        $config = config('scamsense.verified_domains', ['suffixes' => [], 'domains' => []]);

        return new self($config['suffixes'] ?? [], $config['domains'] ?? []);
    }

    /**
     * The verified registrable domain for a URL, or null.
     *
     * Returns the matched domain rather than a boolean so the caller can record
     * it as indicator evidence — a gated verdict must be able to say *which*
     * institution it recognised.
     */
    public function match(string $url): ?string
    {
        $host = $this->hostOf($url);

        if ($host === null) {
            return null;
        }

        $registrable = $this->registrableDomain($host);

        if ($registrable === null) {
            return null;
        }

        if (in_array($registrable, $this->domains, true)) {
            return $registrable;
        }

        foreach ($this->suffixes as $suffix) {
            // Endswith on ".{suffix}" or exact equality. The leading dot is
            // essential: without it "notgov.my" would match the "gov.my"
            // suffix, which is exactly the bypass this class must not permit.
            if ($registrable === $suffix || str_ends_with($registrable, '.'.$suffix)) {
                return $registrable;
            }
        }

        return null;
    }

    public function isVerified(string $url): bool
    {
        return $this->match($url) !== null;
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
