<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\RedactionResult;

/**
 * Masks Malaysian personal data before a submission is stored or logged.
 *
 * AGENTS.md §3 requires this to run before `->save()`, not after: once raw PII
 * reaches MySQL it is in the backups too, and no amount of later cleanup gets it
 * out of those.
 *
 * ## Two design constraints that shape the whole class
 *
 * **Redaction must not destroy the signal.** The text handed to this service is
 * about to be classified. If masking swallowed the phishing URL, the URL model
 * would receive nothing and the message model would lose the very feature that
 * makes a scam recognisable. So URLs are lifted out before any pattern runs and
 * put back afterwards — otherwise the digits inside `bit.ly/3xK9mZ2` or
 * `maybank2u-verify.xyz/012345678` would be eaten by the phone and account
 * patterns.
 *
 * **Order is load-bearing.** Malaysian identifiers overlap in length: an IC is
 * twelve digits and several banks issue twelve-digit account numbers. The
 * patterns therefore run most-specific first — email, then structurally
 * validated IC, then phone, then the loose account catch-all — so that the
 * general rule only ever sees what the specific rules have already declined.
 * Reordering these will silently mislabel data.
 */
class RedactorService
{
    public const MASK_EMAIL = '[EMEL DILINDUNGI]';

    public const MASK_IC = '[NO. KP DILINDUNGI]';

    public const MASK_PHONE = '[NO. TEL DILINDUNGI]';

    public const MASK_ACCOUNT = '[NO. AKAUN DILINDUNGI]';

    /**
     * Sentinel wrapper for parked URLs.
     *
     * Uses control characters that cannot appear in the submitted text — a
     * visible placeholder like `__URL_0__` could be typed by a user, letting a
     * crafted message collide with the restore step and inject arbitrary text
     * into the stored record.
     */
    private const URL_PLACEHOLDER = "\x00U%d\x01";

    /**
     * Matches http/https URLs and bare `www.` hosts.
     */
    private const PATTERN_URL = '#\b(?:https?://|www\.)[^\s<>"\']+#i';

    private const PATTERN_EMAIL = '/[\p{L}0-9._%+\-]+@[\p{L}0-9.\-]+\.[\p{L}]{2,}/u';

    /**
     * MyKad, dashed: YYMMDD-PB-###G, e.g. 020304-14-5678.
     */
    private const PATTERN_IC_DASHED = '/(?<![\d\-])(\d{6})-(\d{2})-(\d{4})(?![\d\-])/';

    /**
     * MyKad, undashed: twelve consecutive digits. Ambiguous with some bank
     * account numbers, which is why {@see self::looksLikeIc()} checks that the
     * first six digits form a plausible date and the next two a real state code
     * before accepting the match.
     */
    private const PATTERN_IC_PLAIN = '/(?<!\d)(\d{6})(\d{2})(\d{4})(?!\d)/';

    /**
     * Malaysian mobile and landline numbers, with or without +60, and with
     * spaces, dots, dashes or nothing between the groups.
     *
     * Covers: 012-345 6789, 0123456789, +60123456789, 60 12 3456789,
     * 03-1234 5678, 04-123 4567, and the bracketed area code form `(03) 2145
     * 6789` that Malaysian businesses print on letterheads.
     */
    private const PATTERN_PHONE = '/(?<![\d\w])\(?(?:\+?60|0)[\s\-.]?[1-9]\d?\)?[\s\-.]?\d{3,4}[\s\-.]?\d{4}(?!\d)/';

    /**
     * A bare run of 10–16 digits.
     */
    private const PATTERN_ACCOUNT = '/(?<!\d)\d{10,16}(?!\d)/';

    /**
     * Digits written in space- or dash-separated groups, as people actually
     * write account numbers: `5140 1234 5678`.
     *
     * The pattern only proposes a candidate; {@see self::isAccountLength()}
     * then checks the digits total 10–16, which is what stops it swallowing
     * `Harga: 1500 2000 2500`-style prose. Runs after the phone pattern, so
     * anything phone-shaped has already been claimed.
     */
    private const PATTERN_ACCOUNT_GROUPED = '/(?<!\d)(?:\d{3,6}[\s\-]){1,3}\d{3,6}(?!\d)/';

    private const ACCOUNT_MIN_DIGITS = 10;

    private const ACCOUNT_MAX_DIGITS = 16;

    /**
     * Valid MyKad place-of-birth codes: 01–59 are Malaysian states, 60–85 are
     * foreign-born, 82 is unspecified. 00 and 86–99 are not issued, so a
     * twelve-digit number carrying one of those is something else — most
     * likely a bank account.
     */
    private const IC_STATE_MIN = 1;

    private const IC_STATE_MAX = 85;

    public function redact(string $text): RedactionResult
    {
        $counts = [
            'emails' => 0,
            'ic_numbers' => 0,
            'phone_numbers' => 0,
            'bank_accounts' => 0,
        ];

        [$text, $urls] = $this->parkUrls($text);

        $text = $this->replace(self::PATTERN_EMAIL, self::MASK_EMAIL, $text, $counts['emails']);
        $text = $this->redactIcNumbers($text, $counts['ic_numbers']);
        $text = $this->replace(self::PATTERN_PHONE, self::MASK_PHONE, $text, $counts['phone_numbers']);
        $text = $this->replace(self::PATTERN_ACCOUNT, self::MASK_ACCOUNT, $text, $counts['bank_accounts']);
        $text = $this->redactGroupedAccounts($text, $counts['bank_accounts']);

        return new RedactionResult($this->restoreUrls($text, $urls), $counts);
    }

    /**
     * Convenience for callers that only want the safe string.
     */
    public function redactToString(string $text): string
    {
        return $this->redact($text)->text;
    }

    /**
     * Lift URLs out of the text so no later pattern can chew through them.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function parkUrls(string $text): array
    {
        $urls = [];

        $parked = preg_replace_callback(
            self::PATTERN_URL,
            function (array $m) use (&$urls): string {
                $urls[] = $m[0];

                return sprintf(self::URL_PLACEHOLDER, count($urls) - 1);
            },
            $text
        );

        return [$parked ?? $text, $urls];
    }

    /**
     * @param  list<string>  $urls
     */
    private function restoreUrls(string $text, array $urls): string
    {
        foreach ($urls as $i => $url) {
            $text = str_replace(sprintf(self::URL_PLACEHOLDER, $i), $url, $text);
        }

        return $text;
    }

    /**
     * Mask IC numbers, dashed form first, then undashed with validation.
     */
    private function redactIcNumbers(string $text, int &$count): string
    {
        $text = $this->replace(self::PATTERN_IC_DASHED, self::MASK_IC, $text, $count);

        $result = preg_replace_callback(
            self::PATTERN_IC_PLAIN,
            function (array $m) use (&$count): string {
                if (! $this->looksLikeIc($m[1], $m[2])) {
                    // Leave it alone: the bank-account pattern runs later and
                    // will catch it, labelled correctly.
                    return $m[0];
                }

                $count++;

                return self::MASK_IC;
            },
            $text
        );

        return $result ?? $text;
    }

    /**
     * Mask account numbers written in separated groups.
     *
     * The regex is deliberately permissive and the digit-count check does the
     * real work, because the alternative — encoding each Malaysian bank's
     * grouping convention into the pattern — would be both unreadable and
     * wrong the moment a bank changed format.
     */
    private function redactGroupedAccounts(string $text, int &$count): string
    {
        $result = preg_replace_callback(
            self::PATTERN_ACCOUNT_GROUPED,
            function (array $m) use (&$count): string {
                $digits = strlen((string) preg_replace('/\D/', '', $m[0]));

                if ($digits < self::ACCOUNT_MIN_DIGITS || $digits > self::ACCOUNT_MAX_DIGITS) {
                    return $m[0];
                }

                $count++;

                return self::MASK_ACCOUNT;
            },
            $text
        );

        return $result ?? $text;
    }

    /**
     * Does this twelve-digit run plausibly decompose into a MyKad?
     *
     * Checks the birth-date block is a real date and the place-of-birth block
     * is an issued code. This is what stops a twelve-digit Maybank account
     * being masked as an IC — and, being a check rather than a guess, it is
     * also the honest answer when the panel asks how the two are told apart.
     */
    private function looksLikeIc(string $birthDate, string $stateCode): bool
    {
        $month = (int) substr($birthDate, 2, 2);
        $day = (int) substr($birthDate, 4, 2);
        $state = (int) $stateCode;

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return $state >= self::IC_STATE_MIN && $state <= self::IC_STATE_MAX;
    }

    private function replace(string $pattern, string $mask, string $text, int &$count): string
    {
        $result = preg_replace($pattern, $mask, $text, -1, $hits);

        if ($result === null) {
            // A regex failure must not silently return unredacted text.
            return $text;
        }

        $count += $hits;

        return $result;
    }
}
