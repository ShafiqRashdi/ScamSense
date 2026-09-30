<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\Submission;
use App\Models\TrainingSample;
use App\Services\Admin\CsvExporter;
use App\Services\Admin\DashboardService;
use App\Services\Admin\ModelMetricsReader;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Downloads for the admin reports page.
 *
 * ## One button
 *
 * The page offers a single download: everything, as a zip. Six buttons in a row
 * was six decisions to make before getting any data, and the honest answer to
 * "which of these do I want?" is almost always "all of them" — the files are
 * kilobytes, and the reason to open this page at all is to have the data
 * somewhere it can be sorted.
 *
 * The individual files are still routed, behind a disclosure. They cost nothing
 * to keep, they are the fallback when `ext-zip` is unavailable, and someone who
 * wants only the moderation trail should not have to download the corpus to get
 * it.
 *
 * ## What is true of every route here
 *
 * - The route group already applies `auth` and the moderator role check. There
 *   is no second check written inline, because a check written inline is one
 *   that gets forgotten on the next route somebody adds.
 * - Every export is logged. Submissions contain text a third party wrote, and
 *   under PDPA 2010 knowing who took a copy is part of holding it responsibly.
 *   The line records who, what and how many rows — never the content itself,
 *   which would put the thing being protected into the log file.
 * - Nothing is cached, and the routes are throttled in `web.php`: these are
 *   full table scans, and a held-down refresh key should not be able to sit on
 *   the one database this project has.
 */
class ExportController extends Controller
{
    public function __construct(
        private CsvExporter $csv,
        private DashboardService $dashboard,
        private ModelMetricsReader $metrics,
    ) {}

    /**
     * Everything, as one zip. The button on the reports page.
     *
     * Each file is written to disk and added by path rather than built as a
     * string and added with `addFromString`, which would hold every export in
     * memory at once and give back the problem the streaming exporter exists to
     * avoid.
     */
    public function archive(): BinaryFileResponse
    {
        abort_unless(self::zipIsAvailable(), 503, 'The zip extension is not installed on this server.');

        $stamp = now()->format('Y-m-d');
        $filename = "scamsense-export-{$stamp}.zip";

        // The CSVs go in a directory of their own so it can be removed whole,
        // and the zip sits outside it so it survives that removal until the
        // response has been sent.
        $root = storage_path('app/exports');
        $workspace = $root.DIRECTORY_SEPARATOR.uniqid('archive-');
        mkdir($workspace, 0700, true);

        $zipPath = $root.DIRECTORY_SEPARATOR.uniqid('archive-').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $rows = 0;
        $written = [];

        foreach ($this->datasets() as $dataset) {
            $path = $workspace.DIRECTORY_SEPARATOR.$dataset['filename']($stamp);
            $handle = fopen($path, 'wb');
            $rows += ($dataset['write'])($handle);
            fclose($handle);

            $zip->addFile($path, basename($path));
            $written[] = $path;
        }

        // close() is what actually reads the added files and writes the
        // archive, so nothing may be deleted before it returns.
        $zip->close();

        /*
         | Remove the loose CSVs now, by the paths just written.
         |
         | Now rather than on a send callback, because their bytes are already
         | inside the zip and a directory of exported personal data is the last
         | thing this application should leave in storage -- least of all
         | waiting on a callback that never runs if the client disconnects
         | mid-download.
         |
         | By path rather than by glob: an earlier version globbed the workspace
         | and silently deleted nothing, leaving a folder of redacted-but-still-
         | personal text behind after every download. The paths are already in
         | hand, so there is no reason to go looking for them.
         */
        foreach ($written as $path) {
            @unlink($path);
        }

        @rmdir($workspace);

        $this->audit('archive', $rows);

        $response = response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();

        // Set AFTER construction: Laravel's download() marks the response
        // public, which would let a shared cache keep a copy of every export.
        // The single-file routes already send no-store; the zip carries the
        // same data, all of it, and went out as `Cache-Control: public`.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Submissions with the verdict each one produced. */
    public function submissions(): StreamedResponse
    {
        return $this->single('submissions');
    }

    /** Moderation decisions — the audit trail for ADR 0008's gate. */
    public function feedback(): StreamedResponse
    {
        return $this->single('feedback');
    }

    /** The retraining set, with the dataset version each row was exported into. */
    public function trainingSamples(): StreamedResponse
    {
        return $this->single('training');
    }

    /** Indicator frequency. */
    public function indicators(): StreamedResponse
    {
        return $this->single('indicators');
    }

    /** Verdict counts per model version — the traceability table. */
    public function modelVersions(): StreamedResponse
    {
        return $this->single('model-versions');
    }

    /** Every model's metrics, as JSON. */
    public function modelMetrics(): StreamedResponse
    {
        return $this->single('model-metrics');
    }

    /** Whether the one-button download can be offered at all. */
    public static function zipIsAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /** Stream one dataset on its own. */
    private function single(string $key): StreamedResponse
    {
        $dataset = $this->datasets()[$key];
        $filename = $dataset['filename'](now()->format('Y-m-d'));

        $this->audit($key, $dataset['count']());

        return response()->streamDownload(
            function () use ($dataset): void {
                $handle = fopen('php://output', 'wb');
                ($dataset['write'])($handle);
                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => str_ends_with($filename, '.json')
                    ? 'application/json; charset=UTF-8'
                    : 'text/csv; charset=UTF-8',
                // Derived from live data; never served from a cache to whoever
                // asks next.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * The six exports, described once.
     *
     * Declared in one place because the archive and the individual routes must
     * produce byte-identical files. Two code paths writing "the submissions
     * export" is how the zip quietly starts carrying a different set of columns
     * from the CSV beside it.
     *
     * Each `write` takes a file handle and returns the row count, so the same
     * closure serves a streamed response and a file inside the zip.
     *
     * @return array<string, array{filename: callable(string): string, count: callable(): int, write: callable(mixed): int}>
     */
    private function datasets(): array
    {
        return [
            'submissions' => [
                'filename' => fn (string $stamp): string => "scamsense-submissions-{$stamp}.csv",
                'count' => fn (): int => Submission::query()->count(),
                /*
                 | `content_redacted` is the column, never `content` — there is
                 | no unredacted column to export, because redaction runs before
                 | the row is written rather than on the way out. That is the
                 | single most important sentence about this file: an export
                 | cannot leak what was never stored.
                 */
                'write' => fn (mixed $handle): int => $this->csv->writeTo(
                    $handle,
                    Submission::query()->with('latestVerdict')->orderBy('id'),
                    [
                        'id', 'submitted_at', 'type', 'content_redacted',
                        'label', 'risk_level', 'confidence', 'model_version',
                        'is_partial', 'partial_reason', 'inference_ms',
                    ],
                    fn (Submission $submission): array => [
                        $submission->id,
                        $submission->created_at?->toDateTimeString(),
                        $submission->type,
                        $submission->content_redacted,
                        $submission->latestVerdict?->label,
                        $submission->latestVerdict?->risk_level,
                        $submission->latestVerdict?->confidence,
                        $submission->latestVerdict?->model_version,
                        $submission->latestVerdict?->is_partial,
                        $submission->latestVerdict?->partial_reason,
                        $submission->latestVerdict?->inference_ms,
                    ],
                ),
            ],

            'feedback' => [
                'filename' => fn (string $stamp): string => "scamsense-feedback-{$stamp}.csv",
                'count' => fn (): int => Feedback::query()->count(),
                /*
                 | The export that answers "prove no user feedback reached a
                 | model without a human approving it", which a panel is
                 | entitled to ask. Every row carries who moderated it and when.
                 */
                'write' => fn (mixed $handle): int => $this->csv->writeTo(
                    $handle,
                    Feedback::query()->with('moderator')->orderBy('id'),
                    [
                        'id', 'verdict_id', 'agrees', 'comment', 'status',
                        'moderated_by', 'moderated_at', 'moderation_note', 'submitted_at',
                    ],
                    fn (Feedback $feedback): array => [
                        $feedback->id,
                        $feedback->verdict_id,
                        $feedback->agrees,
                        $feedback->comment,
                        $feedback->status,
                        $feedback->moderator?->name,
                        $feedback->moderated_at?->toDateTimeString(),
                        $feedback->moderation_note,
                        $feedback->created_at?->toDateTimeString(),
                    ],
                ),
            ],

            'training' => [
                'filename' => fn (string $stamp): string => "scamsense-training-set-{$stamp}.csv",
                'count' => fn (): int => TrainingSample::query()->count(),
                /*
                 | `origin` and `dataset_version` are the two columns that
                 | matter: together they say which rows came from the public,
                 | who approved each one, and which numbered dataset it has been
                 | exported into. A blank version has been approved but never
                 | trained on.
                 */
                'write' => fn (mixed $handle): int => $this->csv->writeTo(
                    $handle,
                    TrainingSample::query()->with('approver')->orderBy('id'),
                    [
                        'id', 'origin', 'type', 'label', 'content',
                        'approved_by', 'approved_at', 'dataset_version', 'exported_at',
                    ],
                    fn (TrainingSample $sample): array => [
                        $sample->id,
                        $sample->origin,
                        $sample->type,
                        $sample->label,
                        $sample->content,
                        $sample->approver?->name,
                        $sample->approved_at?->toDateTimeString(),
                        $sample->dataset_version,
                        $sample->exported_at?->toDateTimeString(),
                    ],
                ),
            ],

            'indicators' => [
                'filename' => fn (string $stamp): string => "scamsense-indicators-{$stamp}.csv",
                'count' => fn (): int => count($this->dashboard->topIndicators(limit: 500)),
                'write' => fn (mixed $handle): int => $this->csv->writeRowsTo(
                    $handle,
                    ['code', 'source', 'count'],
                    array_map(
                        fn (array $indicator): array => [
                            $indicator['code'],
                            $indicator['source'],
                            $indicator['count'],
                        ],
                        $this->dashboard->topIndicators(limit: 500),
                    ),
                ),
            ],

            'model-versions' => [
                'filename' => fn (string $stamp): string => "scamsense-model-versions-{$stamp}.csv",
                'count' => fn (): int => count($this->dashboard->verdictsByModelVersion()),
                'write' => function (mixed $handle): int {
                    $rows = [];
                    foreach ($this->dashboard->verdictsByModelVersion() as $version => $count) {
                        $rows[] = [$version, $count];
                    }

                    return $this->csv->writeRowsTo($handle, ['model_version', 'verdicts'], $rows);
                },
            ],

            'model-metrics' => [
                'filename' => fn (string $stamp): string => "scamsense-model-metrics-{$stamp}.json",
                'count' => fn (): int => count($this->metrics->all()),
                /*
                 | JSON rather than CSV because these are the committed
                 | metrics.json files themselves — nested confusion matrices and
                 | a reporting qualifier that must travel with its figure.
                 | Flattening them into columns would be the first step towards
                 | quoting an F1 without its caveat, which is the thing
                 | ModelMetricsReader exists to prevent.
                 */
                'write' => function (mixed $handle): int {
                    $models = $this->metrics->all();
                    fwrite($handle, (string) json_encode(
                        $models,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ));

                    return count($models);
                },
            ],
        ];
    }

    /**
     * One line per download: who took what, and how much of it.
     *
     * Never the content. A log that recorded the exported rows in order to
     * prove they were exported has copied the personal data into a second place
     * with a longer retention than the table it came from.
     */
    private function audit(string $export, int $rows): void
    {
        Log::info('admin.export', [
            'export' => $export,
            'rows' => $rows,
            'user_id' => auth()->id(),
        ]);
    }
}
