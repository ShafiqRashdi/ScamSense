<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin dashboard (Phase 8).
 *
 * Thin: one service call, one view. The role check is middleware on the route
 * group, not an `if` in here — AGENTS.md requires an explicit role check on
 * every admin route, and a check written inline is one that gets forgotten on
 * the next route somebody adds.
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(Request $request): View
    {
        $days = (int) $request->integer('days', 30);
        $days = max(7, min($days, 90));

        return view('admin.dashboard', [
            'data' => $this->dashboard->summary($days),
            'byModelVersion' => $this->dashboard->verdictsByModelVersion(),
            'days' => $days,
        ]);
    }
}
