<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\FeedbackStatus;
use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\TrainingSampleOrigin;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\ThreatIntelCache;
use App\Models\TrainingSample;
use App\Models\Verdict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the figures the admin dashboard shows (Phase 8).
 *
 * All of it is derived from what the system actually recorded. Nothing here
 * computes a model metric — those come from the committed `metrics.json` files
 * so that a number on the dashboard and a number in Chapter 4 cannot disagree.
 */
class DashboardService
{
    public function __construct(private ModelMetricsReader $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        return [
            'volume' => $this->submissionVolume($days),
            'totals' => $this->totals(),
            'verdicts' => $this->verdictDistribution(),
            'indicators' => $this->topIndicators(),
            'partial' => $this->partialRate(),
            'moderation' => $this->moderationQueue(),
            'training' => $this->trainingSet(),
            'threatIntel' => $this->threatIntelCache(),
            'models' => $this->metrics->all(),
        ];
    }

    /**
     * Daily submission counts, zero-filled.
     *
     * Days with no traffic are included as zeros rather than omitted: a chart
     * that silently skips empty days compresses a quiet week into a busy-looking
     * line, which would misrepresent adoption in a report screenshot.
     *
     * @return array<string, mixed>
     */
    public function submissionVolume(int $days): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $rows = Submission::query()
            ->selectRaw('DATE(created_at) as day, type, COUNT(*) as total')
            ->where('created_at', '>=', $since)
            ->groupBy('day', 'type')
            ->get();

        $series = [];
        for ($date = $since->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $series[$key] = ['date' => $key, 'message' => 0, 'url' => 0, 'total' => 0];
        }

        foreach ($rows as $row) {
            $key = (string) $row->day;
            if (! isset($series[$key])) {
                continue;
            }

            // `type` is cast to a SubmissionType by the model, and the cast
            // still applies to a raw aggregate select — so this is an enum
            // instance, not a string, and using it as an array key throws.
            $type = $row->type instanceof SubmissionType
                ? $row->type->value
                : (string) $row->type;

            $series[$key][$type] = (int) $row->total;
            $series[$key]['total'] += (int) $row->total;
        }

        $values = array_values($series);

        return [
            'days' => $days,
            'series' => $values,
            'peak' => max([0, ...array_column($values, 'total')]),
            'total' => array_sum(array_column($values, 'total')),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        return [
            'submissions' => Submission::query()->count(),
            'verdicts' => Verdict::query()->count(),
            'feedback' => Feedback::query()->count(),
            'unique_content' => (int) Submission::query()->distinct()->count('content_hash'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verdictDistribution(): array
    {
        $byRisk = Verdict::query()
            ->selectRaw('risk_level, COUNT(*) as total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level');

        $byLabel = Verdict::query()
            ->selectRaw('label, COUNT(*) as total')
            ->groupBy('label')
            ->pluck('total', 'label');

        $total = max(1, (int) $byRisk->sum());

        return [
            'risk' => collect(RiskLevel::cases())
                ->map(fn (RiskLevel $level): array => [
                    'level' => $level->value,
                    'colour' => $level->colour(),
                    'count' => (int) ($byRisk[$level->value] ?? 0),
                    'share' => (int) ($byRisk[$level->value] ?? 0) / $total,
                ])
                ->all(),
            'label' => collect(VerdictLabel::cases())
                ->map(fn (VerdictLabel $label): array => [
                    'label' => $label->value,
                    'count' => (int) ($byLabel[$label->value] ?? 0),
                ])
                ->all(),
            'gated' => Indicator::query()
                ->where('code', 'verified_institutional_domain')
                ->count(),
        ];
    }

    /**
     * The indicators fired most often, split by source.
     *
     * `source` is shown because ADR 0007 requires the verified-domain gate to
     * stay distinguishable from model output. An aggregate that mixed them
     * would be the exact conflation that ADR forbids.
     *
     * @return list<array<string, mixed>>
     */
    public function topIndicators(int $limit = 10): array
    {
        return Indicator::query()
            ->selectRaw('code, source, COUNT(*) as total')
            ->groupBy('code', 'source')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn (Indicator $row): array => [
                'code' => $row->code,
                'source' => $row->source instanceof IndicatorSource
                    ? $row->source->value
                    : (string) $row->source,
                'is_model' => $row->source instanceof IndicatorSource
                    && $row->source->isModelAttribution(),
                'count' => (int) $row->getAttribute('total'),
            ])
            ->all();
    }

    /**
     * How often a verdict was degraded, and why.
     *
     * Worth surfacing: a partial rate creeping upwards means a dependency is
     * failing quietly, which is otherwise invisible until someone reads logs.
     *
     * @return array<string, mixed>
     */
    public function partialRate(): array
    {
        $total = Verdict::query()->count();
        $partial = Verdict::query()->where('is_partial', true)->count();

        return [
            'total' => $total,
            'partial' => $partial,
            'rate' => $total > 0 ? $partial / $total : 0.0,
            'reasons' => Verdict::query()
                ->whereNotNull('partial_reason')
                ->selectRaw('partial_reason, COUNT(*) as total')
                ->groupBy('partial_reason')
                ->orderByDesc('total')
                ->pluck('total', 'partial_reason')
                ->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function moderationQueue(): array
    {
        $byStatus = Feedback::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($byStatus[FeedbackStatus::Pending->value] ?? 0),
            'approved' => (int) ($byStatus[FeedbackStatus::Approved->value] ?? 0),
            'rejected' => (int) ($byStatus[FeedbackStatus::Rejected->value] ?? 0),
            'disputes' => Feedback::query()->where('agrees', false)->count(),
        ];
    }

    /**
     * The retraining set, split by provenance.
     *
     * `community` versus `corpus` is the v1/v2 story in Chapter 4 — exactly how
     * much of a future training set came from users. Showing the split here
     * keeps that answerable at a glance rather than by writing a query.
     *
     * @return array<string, mixed>
     */
    public function trainingSet(): array
    {
        $byOrigin = TrainingSample::query()
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        return [
            'community' => (int) ($byOrigin[TrainingSampleOrigin::Community->value] ?? 0),
            'corpus' => (int) ($byOrigin[TrainingSampleOrigin::Corpus->value] ?? 0),
            'awaiting_export' => TrainingSample::query()->unexported()->count(),
            'versions' => TrainingSample::query()
                ->whereNotNull('dataset_version')
                ->selectRaw('dataset_version, COUNT(*) as total')
                ->groupBy('dataset_version')
                ->pluck('total', 'dataset_version')
                ->all(),
        ];
    }

    /**
     * Threat-intel cache hit rate.
     *
     * Measured as the share of cached entries still within their 24-hour life,
     * which is what determines whether a live demonstration will burn through
     * VirusTotal's four-requests-per-minute free tier.
     *
     * @return array<string, mixed>
     */
    public function threatIntelCache(): array
    {
        $total = ThreatIntelCache::query()->count();
        $fresh = ThreatIntelCache::query()->unexpired()->count();

        return [
            'total' => $total,
            'fresh' => $fresh,
            'expired' => $total - $fresh,
            'hit_rate' => $total > 0 ? $fresh / $total : 0.0,
            'malicious' => ThreatIntelCache::query()->where('is_malicious', true)->count(),
            'by_provider' => ThreatIntelCache::query()
                ->selectRaw('provider, COUNT(*) as total')
                ->groupBy('provider')
                ->pluck('total', 'provider')
                ->all(),
        ];
    }

    /**
     * Which model versions have actually produced verdicts.
     *
     * A verdict is only reproducible if the model that produced it is known
     * (AGENTS.md §4), so this is the audit trail for any screenshot.
     *
     * @return array<string, int>
     */
    public function verdictsByModelVersion(): array
    {
        return DB::table('verdicts')
            ->selectRaw('COALESCE(model_version, "(no model — partial)") as version, COUNT(*) as total')
            ->groupBy('version')
            ->orderByDesc('total')
            ->pluck('total', 'version')
            ->all();
    }
}
