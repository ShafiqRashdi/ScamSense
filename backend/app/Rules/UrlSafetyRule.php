<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects any URL whose scheme is not http or https.
 *
 * That single check blocks `javascript:`, `data:`, `file:` and `vbscript:`
 * payloads — the ones that would become stored XSS if a submission were ever
 * rendered into an attribute, and `file:` which would be an LFI primitive if
 * anything ever fetched it.
 *
 * Parses with `parse_url()` and inspects the parts rather than
 * regex-validating, per the laravel-feature skill: URL regexes are
 * notoriously bypassable and this needs to be right.
 */
class UrlSafetyRule implements ValidationRule
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.url_safety.invalid')->translate();

            return;
        }

        $candidate = trim($value);

        if ($candidate === '') {
            $fail('validation.url_safety.invalid')->translate();

            return;
        }

        // Control characters are stripped by some parsers and honoured by
        // others, which is how "java\nscript:" bypasses a naive check.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            $fail('validation.url_safety.invalid')->translate();

            return;
        }

        $withScheme = str_contains($candidate, '://') ? $candidate : 'http://'.$candidate;
        $parts = parse_url($withScheme);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            $fail('validation.url_safety.invalid')->translate();

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $fail('validation.url_safety.scheme')->translate();

            return;
        }

        /*
         * A scheme-like prefix before the first slash means the original string
         * carried its own scheme that parse_url did not surface — for example
         * "javascript:alert(1)" becomes "http://javascript:alert(1)" once a
         * scheme is prepended, and parses with host "javascript".
         */
        if (! str_contains($candidate, '://')) {
            $beforeSlash = strtolower(explode('/', $candidate, 2)[0]);

            if (str_contains($beforeSlash, ':')) {
                $prefix = explode(':', $beforeSlash, 2)[0];

                // A port is fine ("example.com:8080"); a scheme is not.
                if (! ctype_digit(explode(':', $beforeSlash, 2)[1] ?: 'x') && $prefix !== '') {
                    $fail('validation.url_safety.scheme')->translate();
                }
            }
        }
    }
}
