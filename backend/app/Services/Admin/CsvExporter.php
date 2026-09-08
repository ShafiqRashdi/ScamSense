<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads for the admin reports page.
 *
 * Two things make this more than a `fputcsv` wrapper, and both come straight
 * from what this application is.
 *
 * ## 1. Formula injection
 *
 * ScamSense exports attacker-written text by design — that is the product. A
 * cell beginning `=`, `+`, `-`, `@`, tab or carriage return is interpreted as a
 * formula by Excel, LibreOffice and Google Sheets, and a scam message is
 * exactly the kind of string that might start with one. `=cmd|'/c calc'!A1`
 * pasted into a WhatsApp message becomes code execution the moment an
 * administrator opens the export.
 *
 * This is the same problem the browser side already solves by defanging URLs
 * so a submitted link can never be clicked. The spreadsheet is a second
 * rendering context with its own escaping rule, and the rule is: prefix the
 * cell with a single quote, which every major spreadsheet reads as "this is
 * text". `fputcsv` does not do it — it quotes for CSV parsing, which is a
 * different problem entirely.
 *
 * ## 2. Streaming
 *
 * Exports run over whole tables. Building the file in memory would work for a
 * demo corpus and fall over on a real one, and the failure would arrive as a
 * memory-limit fatal on the page an examiner is being shown. Rows are chunked
 * and flushed as they are read.
 */
class CsvExporter
{
    /**
     * Characters a spreadsheet treats as the start of a formula.
     *
     * The tab and carriage return are in the list because a leading whitespace
     * character is stripped by some spreadsheets before the first meaningful
     * character is examined, which puts the payload back at the front.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** Rows held in memory at once. */
    private const CHUNK = 500;

    /**
     * A UTF-8 byte-order mark, written before anything else.
     *
     * Without it Excel on Windows reads the file as the system code page, and
     * every Malay accented character and every redaction marker comes out as
     * mojibake. The corpus is Malaysian; this is not an edge case.
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Stream a query as a CSV download.
     *
     * @param  list<string>  $headers  column names, written as the first row
     * @param  callable(object): list<mixed>  $row  one model to one line
     */
    public function stream(
        string $filename,
        Builder $query,
        array $headers,
        callable $row,
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($query, $headers, $row): void {
                $handle = fopen('php://output', 'wb');

                fwrite($handle, self::BOM);

                fputcsv($handle, $headers);

                $query->chunk(self::CHUNK, function ($records) use ($handle, $row): void {
                    foreach ($records as $record) {
                        fputcsv($handle, array_map(
                            fn (mixed $cell): string => $this->neutralise($cell),
                            $row($record),
                        ));
                    }

                    flush();
                });

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // The export is derived from live data and must never be served
                // from a cache to whoever asks next.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * Stream rows already in memory as a CSV download.
     *
     * For the aggregates — indicator frequency, verdicts by model version —
     * which are a `GROUP BY` rather than a table scan and so cannot be chunked
     * by primary key. They are also tens of rows rather than thousands, so
     * holding them is not the problem streaming exists to solve.
     *
     * @param  list<string>  $headers
     * @param  iterable<array-key, list<mixed>>  $rows
     */
    public function streamRows(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($headers, $rows): void {
                $handle = fopen('php://output', 'wb');
                fwrite($handle, self::BOM);
                fputcsv($handle, $headers);

                foreach ($rows as $row) {
                    fputcsv($handle, array_map(
                        fn (mixed $cell): string => $this->neutralise($cell),
                        $row,
                    ));
                }

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * Make one cell safe to open in a spreadsheet.
     *
     * Public because it is the interesting part and is asserted directly by
     * the tests — a defence nobody can call is a defence nobody can prove.
     */
    public function neutralise(mixed $cell): string
    {
        $value = match (true) {
            $cell === null => '',
            is_bool($cell) => $cell ? '1' : '0',
            // Several exported columns are cast to a backed enum on the model
            // -- status, label, risk_level, type. Casting one of those to
            // string is a TypeError, not a fallback, so it is handled here
            // rather than left for each caller to remember.
            $cell instanceof \BackedEnum => (string) $cell->value,
            // Carbon and anything else with __toString. PHP 8 implements
            // Stringable automatically for those, so this covers timestamps.
            $cell instanceof \Stringable => (string) $cell,
            is_scalar($cell) => (string) $cell,
            // Anything else -- an array, an object with no __toString -- is a
            // caller mistake. An empty cell is a visible bug in the export; a
            // TypeError is a 500 halfway through a streamed response, after
            // the browser has already been told the download succeeded.
            default => '',
        };

        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::FORMULA_TRIGGERS, true)
            ? "'".$value
            : $value;
    }
}
