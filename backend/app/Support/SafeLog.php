<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * Strips secrets and user content out of text before it reaches a log.
 *
 * ## Why this exists — the sanitisation-boundary lesson
 *
 * `RedactorService` was applied correctly and consistently to inbound user
 * content: every submission is redacted before it is persisted or rendered.
 * The Phase 9 security review found that the boundary had a second side nobody
 * had drawn.
 *
 * When a submitted URL is sent *outbound* to a reputation provider, it becomes
 * part of a request — and when that request fails, the HTTP client's exception
 * message embeds the whole URL, which the error handler then logged verbatim.
 * The user's content had re-entered the system through a path that had never
 * been thought of as an input, so no redaction stood in front of it:
 *
 *   - Google Safe Browsing takes the API key as a query parameter, so a timeout
 *     wrote the live key into laravel.log.
 *   - VirusTotal identifies a URL by its base64url encoding in the request
 *     path, so a timeout wrote the submitted URL there in a trivially
 *     reversible form.
 *
 * Both fired on the timeout path, which is the normal failure this code was
 * written to handle gracefully. The lesson generalises: redaction has to be
 * applied where data *leaves* a trust boundary, not only where it arrives, and
 * an exception message is an egress point like any other.
 *
 * @see docs/security-review-2026-08.md findings 1 and 2
 */
class SafeLog
{
    /**
     * Anything at least this long, made only of URL-safe characters, is
     * treated as an opaque token — an API key, a base64url payload, a session
     * identifier. Shorter runs are left alone so ordinary words survive.
     */
    private const TOKEN_MIN_LENGTH = 24;

    /**
     * A short, safe description of why an operation failed.
     *
     * Deliberately lossy. The log needs enough to diagnose a class of failure —
     * timeout, DNS, TLS, HTTP status — and nothing that identifies a user or
     * authenticates a caller.
     */
    public static function reason(Throwable $exception): string
    {
        return self::scrub($exception->getMessage());
    }

    /**
     * Remove secrets and user content from an arbitrary string.
     *
     * Ordered from most specific to least, because each pass can only work on
     * what the previous ones left behind.
     */
    public static function scrub(string $text): string
    {
        // 1. Configured secrets, by exact value. The only pass that can catch a
        //    key which happens to look like an ordinary word.
        foreach (self::configuredSecrets() as $secret) {
            if ($secret !== '' && strlen($secret) >= 8) {
                $text = str_replace($secret, '[secret]', $text);
            }
        }

        // 2. Whole URLs. Guzzle appends " for https://…" to connection errors,
        //    and that tail carries both the API key and the submitted URL.
        $text = (string) preg_replace('#\s+for\s+https?://\S+#i', '', $text);
        $text = (string) preg_replace('#https?://\S+#i', '[url]', $text);

        // 3. Named credential parameters, in case a URL survived in a form the
        //    pass above did not match.
        $text = (string) preg_replace(
            '#\b(key|apikey|api_key|access_token|token|secret|password|pwd)=[^\s&"\']+#i',
            '$1=[secret]',
            $text,
        );

        // 4. Long opaque runs. This is what catches a base64url-encoded
        //    submitted URL sitting in a request path with no query string to
        //    give it away.
        $text = (string) preg_replace(
            '#\b[A-Za-z0-9_\-]{'.self::TOKEN_MIN_LENGTH.',}={0,2}\b#',
            '[token]',
            $text,
        );

        return Str::limit(trim($text), 200);
    }

    /**
     * Every secret the application knows about, so they can be removed by value.
     *
     * @return list<string>
     */
    private static function configuredSecrets(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? $value : '',
            [
                config('services.safe_browsing.key'),
                config('services.virustotal.key'),
                config('services.ml_service.secret'),
                config('app.key'),
                config('database.connections.mysql.password'),
            ],
        )));
    }
}
