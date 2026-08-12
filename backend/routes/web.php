<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ModerationController;
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

Route::view('/dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

/*
 | Admin area. `auth` and the role check are both applied: being signed in and
 | being allowed to moderate are different questions.
 */
Route::middleware(['auth', EnsureUserCanModerate::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');
        Route::post('/moderation/{feedback}/approve', [ModerationController::class, 'approve'])
            ->name('moderation.approve');
        Route::post('/moderation/{feedback}/reject', [ModerationController::class, 'reject'])
            ->name('moderation.reject');
    });

require __DIR__.'/auth.php';
