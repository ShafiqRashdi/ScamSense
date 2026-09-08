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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads for the admin reports page.
 *
 * The reports page exists because the dashboard was showing nine panels of
 * which five were reference rather than anything to act on. Reference material
 * is better as a file than as a table nobody can sort, so each panel there sits
 * beside one of these.
 *
 * Three things are true of every route in here and are worth stating once:
 *
 * - The route group already applies `auth` and the moderator role check. There
 *   is no second check written inline, because a check written inline is one
 *   that gets forgotten on the next route somebody adds.
 * - Every export is logged. Submissions contain text a third party wrote, and
 *   under PDPA 2010 knowing who took a copy is part of holding it responsibly.
 *   The line records who, what and how many rows — never the content itself,
 *   which would put the thing being protected into the log file.
 * - Nothing here is cached. The routes are throttled in `web.php` instead:
 *   these are full table scans and a refresh loop should not be able to sit on
 *   the database.
 */
class ExportController extends Controller
{
    public function __construct(
        private CsvExporter $csv,
        private DashboardService $dashboard,
        private ModelMetricsReader $metrics,
    ) {}

    /**
     * Submissions with the verdict each one produced.
     *
     * `content_redacted` is the column, never `content` — there is no unredacted
     * column to export, because redaction runs before the row is written rather
     * than on the way out. That is the single most important sentence about
     * this file: an export cannot leak what was never stored.
     */
    public function submissions(): StreamedResponse
    {
        $query = Submission::query()->with('latestVerdict')->orderBy('id');

        $this->audit('submissions', $query->clone()->count());

        return $this->csv->stream(
            'scamsense-submissions-'.now()->format('Y-m-d').'.csv',
            $query,
            [
                'id', 'submitted_at', 'type', 'content_redacted',
                'label', 'risk_level', 'confidence', 'model_version',
                'is_partial', 'partial_reason', 'inference_ms',
            ],
            fn (Submission $submission): array => [
                $submission->id,
                $submission->created_at?->toDateTimeString(),
                $submission->type->value,
                $submission->content_redacted,
                $submission->latestVerdict?->label->value,
                $submission->latestVerdict?->risk_level->value,
                $submission->latestVerdict?->confidence,
                $submission->latestVerdict?->model_version,
                $submission->latestVerdict?->is_partial,
                $submission->latestVerdict?->partial_reason,
                $submission->latestVerdict?->inference_ms,
            ],
        );
    }

    /**
     * Moderation decisions — the audit trail for ADR 0008's gate.
     *
     * This is the export that answers "prove no user feedback reached a model
     * without a human approving it", which is a question a panel is entitled to
     * ask. Every row carries who moderated it and when.
     */
    public function feedback(): StreamedResponse
    {
        $query = Feedback::query()->with('moderator')->orderBy('id');

        $this->audit('feedback', $query->clone()->count());

        return $this->csv->stream(
            'scamsense-feedback-'.now()->format('Y-m-d').'.csv',
            $query,
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
        );
    }

    /**
     * The retraining set.
     *
     * `origin` and `dataset_version` are the two columns that matter: together
     * they say which rows came from the public, who approved each one, and
     * which numbered dataset it has been exported into. A row with no
     * `dataset_version` has been approved but never trained on.
     */
    public function trainingSamples(): StreamedResponse
    {
        $query = TrainingSample::query()->with('approver')->orderBy('id');

        $this->audit('training-samples', $query->clone()->count());

        return $this->csv->stream(
            'scamsense-training-set-'.now()->format('Y-m-d').'.csv',
            $query,
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
        );
    }

    /** Indicator frequency — an aggregate, so it is not chunked. */
    public function indicators(): StreamedResponse
    {
        $rows = $this->dashboard->topIndicators(limit: 500);

        $this->audit('indicators', count($rows));

        return $this->csv->streamRows(
            'scamsense-indicators-'.now()->format('Y-m-d').'.csv',
            ['code', 'source', 'count'],
            array_map(
                fn (array $indicator): array => [
                    $indicator['code'],
                    $indicator['source'],
                    $indicator['count'],
                ],
                $rows,
            ),
        );
    }

    /** Verdict counts per model version — the traceability table. */
    public function modelVersions(): StreamedResponse
    {
        $counts = $this->dashboard->verdictsByModelVersion();

        $this->audit('model-versions', count($counts));

        $rows = [];
        foreach ($counts as $version => $count) {
            $rows[] = [$version, $count];
        }

        return $this->csv->streamRows(
            'scamsense-model-versions-'.now()->format('Y-m-d').'.csv',
            ['model_version', 'verdicts'],
            $rows,
        );
    }

    /**
     * Every model's metrics, as JSON.
     *
     * JSON rather than CSV because these are the committed `metrics.json` files
     * themselves — nested confusion matrices and a reporting qualifier that must
     * travel with its figure. Flattening them into columns would be the first
     * step towards quoting an F1 without its caveat, which is the thing
     * `ModelMetricsReader` exists to prevent.
     */
    public function modelMetrics(): JsonResponse
    {
        $models = $this->metrics->all();

        $this->audit('model-metrics', count($models));

        return response()
            ->json($models, options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header(
                'Content-Disposition',
                'attachment; filename="scamsense-model-metrics-'.now()->format('Y-m-d').'.json"',
            );
    }

    /**
     * One line per download: who took what, and how much of it.
     *
     * Never the content. A log that records the exported rows in order to prove
     * they were exported has copied the personal data into a second place with
     * a longer retention than the table it came from.
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
