<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Contracts\Database\Eloquent\Builder;

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
     * Write a query to an open handle as CSV, returning the row count.
     *
     * A handle rather than a response, because the same bytes have to go to two
     * places: straight down a streamed response, and into a file that the
     * one-button zip then adds. Two code paths writing "the submissions export"
     * is how the zip quietly starts carrying a different set of columns from
     * the CSV beside it.
     *
     * @param  resource  $handle
     * @param  list<string>  $headers  column names, written as the first row
     * @param  callable(object): list<mixed>  $row  one model to one line
     */
    public function writeTo(mixed $handle, Builder $query, array $headers, callable $row): int
    {
        fwrite($handle, self::BOM);
        fputcsv($handle, $headers);

        $written = 0;

        $query->chunk(self::CHUNK, function ($records) use ($handle, $row, &$written): void {
            foreach ($records as $record) {
                fputcsv($handle, array_map(
                    fn (mixed $cell): string => $this->neutralise($cell),
                    $row($record),
                ));
                $written++;
            }

            $this->flushChunk($handle);
        });

        return $written;
    }

    /**
     * Whether a handle is the HTTP response itself, rather than a file.
     *
     * Public so the rule below is pinned by a test: it is invisible in a test
     * run, where no real headers are ever sent.
     *
     * @param  resource  $handle
     */
    public static function streamsToResponse(mixed $handle): bool
    {
        return (stream_get_meta_data($handle)['uri'] ?? '') === 'php://output';
    }

    /**
     * Push a finished chunk on its way.
     *
     * PHP's flush() sends the RESPONSE, not the handle -- and the first flush
     * sends the headers with it. For a streamed download that is what we want.
     * For "Download everything" it was the bug: the chunks are written to files
     * that go into a zip, so the first flush() sent empty headers before the zip
     * response existed, Laravel then failed with "headers already sent", and
     * the browser was left on a blank page. The single-file downloads kept
     * working because their headers had already gone out.
     *
     * @param  resource  $handle
     */
    private function flushChunk(mixed $handle): void
    {
        if (self::streamsToResponse($handle)) {
            flush();

            return;
        }

        fflush($handle);
    }

    /**
     * Write rows already in memory, returning the row count.
     *
     * For the aggregates -- indicator frequency, verdicts by model version --
     * which are a `GROUP BY` rather than a table scan and so cannot be chunked
     * by primary key. They are also tens of rows rather than thousands, so
     * holding them is not the problem streaming exists to solve.
     *
     * @param  resource  $handle
     * @param  list<string>  $headers
     * @param  iterable<array-key, list<mixed>>  $rows
     */
    public function writeRowsTo(mixed $handle, array $headers, iterable $rows): int
    {
        fwrite($handle, self::BOM);
        fputcsv($handle, $headers);

        $written = 0;

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (mixed $cell): string => $this->neutralise($cell),
                $row,
            ));
            $written++;
        }

        return $written;
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
