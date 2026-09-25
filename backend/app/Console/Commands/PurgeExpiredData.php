<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Submission;
use App\Models\ThreatIntelCache;
use App\Models\TrainingSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes personal data past its retention period (security review finding 5).
 *
 * The submission form tells users their message is kept so detection can be
 * improved. Before this existed, nothing ever deleted anything — the promise
 * and the behaviour did not match, and PDPA 2010 requires personal data not be
 * kept longer than necessary.
 *
 * ## What is kept, and what is not
 *
 * Submissions and everything cascading from them are deleted after the
 * retention period. Verdicts, indicators and feedback go with them through the
 * existing foreign keys.
 *
 * **Approved training samples are exempt and deliberately so.** A moderator
 * looked at that row and decided it belongs in a retraining set; deleting it
 * silently would undo a human decision and quietly shrink a dataset a model was
 * trained on, breaking reproducibility. Those rows carry only redacted content,
 * which is why keeping them is defensible.
 *
 * Expired threat-intel cache rows are cleared too. They hold no personal data —
 * the key is a URL hash — but an unbounded cache is its own problem.
 */
class PurgeExpiredData extends Command
{
    protected $signature = 'scamsense:purge
                            {--days= : Override the configured retention period}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete submissions past the retention period, and expired cache entries';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('scamsense.retention.submission_days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('Retention period must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $this->info("Retention period: {$days} days (cutoff {$cutoff->toDateString()})");

        /*
         * Submissions that still back an approved training sample are kept
         * regardless of age. The row was promoted by a human decision, and the
         * training sample references it.
         */
        $protected = TrainingSample::query()
            ->whereNotNull('submission_id')
            ->pluck('submission_id')
            ->all();

        $query = Submission::query()
            ->where('created_at', '<', $cutoff)
            ->when($protected !== [], fn ($q) => $q->whereNotIn('id', $protected));

        $submissionCount = (clone $query)->count();
        $expiredCache = ThreatIntelCache::query()->where('expires_at', '<', now())->count();

        $this->line("  submissions to delete       {$submissionCount}");
        $this->line('  protected by a training row '.count($protected));
        $this->line("  expired cache entries       {$expiredCache}");

        if ($dryRun) {
            $this->comment('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        // Chunked so a large backlog cannot exhaust memory or hold one enormous
        // transaction open against the production database.
        $deleted = 0;
        DB::transaction(function () use ($query, &$deleted): void {
            $query->clone()->select('id')->chunkById(500, function ($rows) use (&$deleted): void {
                $ids = $rows->pluck('id')->all();
                Submission::query()->whereIn('id', $ids)->delete();
                $deleted += count($ids);
            });
        });

        $cacheDeleted = ThreatIntelCache::query()->where('expires_at', '<', now())->delete();

        $this->info("Deleted {$deleted} submissions and {$cacheDeleted} expired cache entries.");
        $this->comment('Verdicts, indicators and feedback were removed by cascade.');

        return self::SUCCESS;
    }
}
