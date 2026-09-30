<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\VerifyController;
use App\Http\Middleware\EnsureUserCanModerate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
*/

Route::get('/', [VerifyController::class, 'show'])->name('verify.show');

/*
 | The two reading pages. Static: nothing is submitted, stored or looked up, so
 | they need no controller and no rate limit beyond the site's defaults.
 */
Route::view('/about', 'pages.about')->name('about');
Route::view('/how-to-use', 'pages.howto')->name('howto');

/*
 | Rate limits from AGENTS.md §3: 10/minute and 30/hour per IP. Both apply —
 | the per-minute limit stops a burst, the hourly one stops a slow grind that
 | would stay under it all day.
 */
Route::post('/verify', [VerifyController::class, 'store'])
    ->middleware(['throttle:verify-minute', 'throttle:verify-hour'])
    ->name('verify.store');

/*
 | Language switch. The locale is whitelisted against a fixed list rather than
 | taken from the URL, so a crafted value cannot reach the translator or the
 | session as arbitrary text.
 */
Route::get('/locale/{locale}', function (string $locale): RedirectResponse {
    if (in_array($locale, ['en', 'ms'], true)) {
        session()->put('locale', $locale);
    }

    return back();
})->name('locale.switch');

/*
 | Community feedback (objective O3). Unauthenticated write path, so it gets
 | the same treatment as verification: validated in a FormRequest, rate limited
 | per IP, and deduplicated by a database constraint.
 |
 | 5/hour from AGENTS.md §3. The per-minute limit is tighter than verification's
 | because there is no legitimate reason to leave feedback in a burst.
 */
Route::post('/feedback', [FeedbackController::class, 'store'])
    ->middleware(['throttle:feedback-minute', 'throttle:feedback-hour'])
    ->name('feedback.store');

/*
 | Admin area. `auth` and the role check are both applied: being signed in and
 | being allowed to moderate are different questions.
 */
Route::middleware(['auth', EnsureUserCanModerate::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');
        Route::post('/moderation/{feedback}/approve', [ModerationController::class, 'approve'])
            ->name('moderation.approve');
        Route::post('/moderation/{feedback}/reject', [ModerationController::class, 'reject'])
            ->name('moderation.reject');

        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');

        /*
         | Downloads.
         |
         | Throttled, unlike the rest of the admin area. Each of these is a full
         | table scan streamed to the browser, so a held-down refresh key is a
         | denial of service against the one database this project has. Ten a
         | minute is far more than anyone reviewing data needs and far less than
         | a loop would manage.
         |
         | Every route is a GET returning an attachment: nothing here changes
         | state, so a download is safe to repeat and safe to bookmark.
         */
        Route::middleware('throttle:10,1')->prefix('exports')->name('exports.')->group(function (): void {
            // The button. Everything, one file.
            Route::get('/all.zip', [ExportController::class, 'archive'])->name('all');

            // The individual files, behind a disclosure on the page. Kept
            // because they are the fallback when ext-zip is unavailable, and
            // because wanting only the moderation trail should not mean
            // downloading the corpus to get it.
            Route::get('/submissions.csv', [ExportController::class, 'submissions'])->name('submissions');
            Route::get('/feedback.csv', [ExportController::class, 'feedback'])->name('feedback');
            Route::get('/training-set.csv', [ExportController::class, 'trainingSamples'])->name('training');
            Route::get('/indicators.csv', [ExportController::class, 'indicators'])->name('indicators');
            Route::get('/model-versions.csv', [ExportController::class, 'modelVersions'])->name('model-versions');
            Route::get('/model-metrics.json', [ExportController::class, 'modelMetrics'])->name('model-metrics');
        });
    });

require __DIR__.'/auth.php';
