<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\AddressAnatomy;
use App\DataTransferObjects\IndicatorData;
use App\DataTransferObjects\MarkedSegment;
use App\DataTransferObjects\SubmissionExplanation;
use App\Enums\SubmissionType;
use App\Services\Detection\VerifiedDomainRegistry;

/**
 * Connects each reason on the result page to the words it came from.
 *
 * The indicator list tells a reader WHAT was noticed; this finds WHERE, so the
 * page can number the reason and mark the same number in their own text. The
 * product's third step promises "the reasons — so the next one you can spot
 * yourself", and a reason you cannot locate in the message does not teach that.
 *
 * Honest by construction: a mark is drawn only where the evidence the ML
 * service returned is actually found in the text. Nothing is inferred, and an
 * indicator whose evidence cannot be located simply goes unnumbered.
 */
class SubmissionExplainer
{
    /** Evidence shorter than this matches too much to point at anything. */
    private const MIN_EVIDENCE_LENGTH = 3;

    public function __construct(
        private DefangService $defang,
        private RedactorService $redactor,
        private VerifiedDomainRegistry $domains,
    ) {}

    /**
     * @param  list<IndicatorData>  $indicators
     */
    public function explain(SubmissionType $type, string $contentRedacted, array $indicators): SubmissionExplanation
    {
        $text = $this->defang->defangUrlsIn($contentRedacted);

        /** @var list<array{start: int, length: int, number: int}> $ranges */
        $ranges = [];
        $numbers = [];
        $next = 1;

        foreach ($indicators as $indicator) {
            $range = $indicator->evidence === null
                ? null
                : $this->locate($text, $indicator->evidence, $ranges);

            if ($range === null) {
                $numbers[] = null;

                continue;
            }

            $ranges[] = [...$range, 'number' => $next];
            $numbers[] = $next;
            $next++;
        }

        $copiedName = false;
        foreach ($indicators as $indicator) {
            $copiedName = $copiedName || $indicator->code === 'brand_lookalike';
        }

        return new SubmissionExplanation(
            segments: $this->segments($text, $ranges),
            numbers: $numbers,
            anatomy: $type === SubmissionType::Url ? $this->anatomy($contentRedacted, $copiedName) : null,
        );
    }

    /**
     * Where one piece of evidence sits in the defanged text, avoiding any span
     * already claimed by an earlier indicator.
     *
     * Evidence arrives in the form the ML service saw — undefanged, and for
     * messages trimmed with an ellipsis either side — so it is normalised the
     * same way the text was before searching.
     *
     * @param  list<array{start: int, length: int, number: int}>  $taken
     * @return array{start: int, length: int}|null
     */
    private function locate(string $text, string $evidence, array $taken): ?array
    {
        $evidence = $this->redactor->redactToString($evidence);
        $evidence = trim((string) preg_replace('/^\x{2026}|\x{2026}$/u', '', trim($evidence)));

        if (mb_strlen($evidence) < self::MIN_EVIDENCE_LENGTH) {
            return null;
        }

        $candidates = array_unique([
            $this->defang->defangUrlsIn($evidence),
            str_replace('.', '[.]', $evidence),
            $evidence,
        ]);

        foreach ($candidates as $candidate) {
            $length = mb_strlen($candidate);
            $offset = 0;

            while (($start = mb_stripos($text, $candidate, $offset)) !== false) {
                if (! $this->overlaps($start, $length, $taken)) {
                    return ['start' => $start, 'length' => $length];
                }

                $offset = $start + 1;
            }
        }

        return null;
    }

    /**
     * @param  list<array{start: int, length: int, number: int}>  $taken
     */
    private function overlaps(int $start, int $length, array $taken): bool
    {
        foreach ($taken as $range) {
            if ($start < $range['start'] + $range['length'] && $range['start'] < $start + $length) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{start: int, length: int, number: int}>  $ranges
     * @return list<MarkedSegment>
     */
    private function segments(string $text, array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $segments = [];
        $cursor = 0;

        foreach ($ranges as $range) {
            if ($range['start'] > $cursor) {
                array_push($segments, ...$this->plain(mb_substr($text, $cursor, $range['start'] - $cursor)));
            }

            $segments[] = new MarkedSegment(mb_substr($text, $range['start'], $range['length']), $range['number']);
            $cursor = $range['start'] + $range['length'];
        }

        if ($cursor < mb_strlen($text)) {
            array_push($segments, ...$this->plain(mb_substr($text, $cursor)));
        }

        return $segments;
    }

    /**
     * Unmarked text, with each redaction mask split out so the page can show
     * it as something ScamSense did rather than something the sender wrote.
     *
     * @return list<MarkedSegment>
     */
    private function plain(string $text): array
    {
        $masks = implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), [
            RedactorService::MASK_EMAIL,
            RedactorService::MASK_IC,
            RedactorService::MASK_PHONE,
            RedactorService::MASK_ACCOUNT,
        ]));

        $parts = preg_split('/('.$masks.')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        return array_map(
            static fn (string $part): MarkedSegment => new MarkedSegment(
                $part,
                isMask: in_array($part, [
                    RedactorService::MASK_EMAIL,
                    RedactorService::MASK_IC,
                    RedactorService::MASK_PHONE,
                    RedactorService::MASK_ACCOUNT,
                ], true),
            ),
            $parts,
        );
    }

    /**
     * Split a submitted address around the domain that says who owns it.
     *
     * Null for anything that is not a named host (an IP address has no owner
     * to point at) and whenever the parts cannot be located exactly — a wrong
     * breakdown would teach the wrong habit, so none is better.
     */
    private function anatomy(string $url, bool $copiedName): ?AddressAnatomy
    {
        $raw = trim($url);
        $candidate = str_contains($raw, '://') ? $raw : 'http://'.$raw;
        $host = parse_url($candidate, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $owner = $this->domains->registrableDomain(strtolower($host));
        $hostAt = stripos($raw, $host);

        if ($owner === null || $hostAt === false) {
            return null;
        }

        $hostRaw = substr($raw, $hostAt, strlen($host));
        $ownerAt = strlen($hostRaw) - strlen($owner);
        $bracket = static fn (string $s): string => str_replace('.', '[.]', $s);
        $lead = (string) preg_replace(['#^https://#i', '#^http://#i'], ['hxxps://', 'hxxp://'], substr($raw, 0, $hostAt));
        $prefix = substr($hostRaw, 0, $ownerAt);

        return new AddressAnatomy(
            lead: $lead,
            prefix: $bracket($prefix),
            owner: $bracket(substr($hostRaw, $ownerAt)),
            tail: substr($raw, $hostAt + strlen($host)),
            prefixIsCopiedName: $copiedName && $prefix !== '',
        );
    }
}
