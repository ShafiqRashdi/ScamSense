<?php

declare(strict_types=1);

use App\Http\Controllers\VerifyController;
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
    ->middleware([
        'throttle:'.config('scamsense.rate_limits.verify_per_minute').',1',
        'throttle:'.config('scamsense.rate_limits.verify_per_hour').',60',
    ])
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

Route::view('/dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

require __DIR__.'/auth.php';
