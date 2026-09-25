<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\View\View;

/**
 * Reports and downloads (Phase 8).
 *
 * The dashboard was rendering nine panels, of which five were reference rather
 * than anything an administrator would act on today: model metrics, indicator
 * frequency, the threat-intel cache, the retraining set, and verdict counts per
 * model version. Together they made a page to be searched rather than read, and
 * they buried the one number worth checking daily — the degraded-verdict rate.
 *
 * The split is by the question each panel answers. The dashboard answers "is
 * anything wrong, and is there anything for me to do?"; this page answers "what
 * does the data say, and can I take it away?" Every panel here sits beside a
 * download, because a table of five hundred indicator codes is more useful as a
 * file than as something to scroll.
 *
 * Thin, like the dashboard: one service call, one view.
 */
class ReportsController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(): View
    {
        return view('admin.reports', ['data' => $this->dashboard->reference()]);
    }
}
