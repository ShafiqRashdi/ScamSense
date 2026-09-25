<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders a submitted URL unclickable.
 *
 * `https://evil.example/login` becomes `hxxps://evil[.]example/login`.
 *
 * The point is not obfuscation — the user asked about this link and must be
 * able to read it. The point is that no layer between here and the user's eye
 * turns it back into something clickable. Blade escaping stops it becoming an
 * anchor, but email clients, chat apps, screenshot tools and the browser's own
 * autolink behaviour will happily linkify a bare URL in text, and a user who
 * has just been told a link is dangerous must not be one accidental tap away
 * from opening it.
 *
 * Defanging is presentation only. The stored `content_redacted` keeps the real
 * URL so the model, the threat-intel cache and any later analysis all see what
 * was actually submitted.
 */
class DefangService
{
    public function defang(string $url): string
    {
        $result = trim($url);

        // Scheme first, so the "://" is gone before dots are handled.
        $result = preg_replace('#^https://#i', 'hxxps://', $result) ?? $result;
        $result = preg_replace('#^http://#i', 'hxxp://', $result) ?? $result;

        // Only dots in the authority are bracketed. Bracketing every dot would
        // mangle the path — `/login.do` reading as `/login[.]do` implies the
        // path is part of the deception, which it usually is not.
        $separator = str_contains($result, '://') ? '://' : '';

        if ($separator === '') {
            return $this->bracketAuthority($result);
        }

        [$scheme, $rest] = explode('://', $result, 2);
        $slash = strpos($rest, '/');

        if ($slash === false) {
            return $scheme.'://'.$this->bracketAuthority($rest);
        }

        return $scheme.'://'
            .$this->bracketAuthority(substr($rest, 0, $slash))
            .substr($rest, $slash);
    }

    /**
     * Defang every URL found inside a longer piece of text.
     *
     * Used for message submissions, where the dangerous link is embedded in
     * prose rather than being the whole submission.
     */
    public function defangUrlsIn(string $text): string
    {
        return preg_replace_callback(
            '#(?:https?://|www\.)[^\s<>"\']+#i',
            fn (array $match): string => $this->defang($match[0]),
            $text,
        ) ?? $text;
    }

    private function bracketAuthority(string $authority): string
    {
        $slash = strpos($authority, '/');
        $host = $slash === false ? $authority : substr($authority, 0, $slash);
        $tail = $slash === false ? '' : substr($authority, $slash);

        return str_replace('.', '[.]', $host).$tail;
    }
}
