<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes
|--------------------------------------------------------------------------
|
| ScamSense has no public user accounts. The `users` table exists only for
| administrators who moderate the community-feedback queue (AGENTS.md §3, and
| the Phase 7/8 objectives). Laravel Breeze's self-service registration,
| password reset, email verification and password confirmation routes were
| therefore removed: they are attack surface that serves none of O1/O2/O3.
|
| Administrators are created by a seeder, not by signing up. See
| database/seeders — and note that AGENTS.md forbids default credentials.
|
| Login is throttled at two layers, and the layers are not redundant:
|
|   • LoginRequest::ensureIsNotRateLimited() keys on email+IP. It stops someone
|     grinding one account's password, but an attacker rotating through many
|     usernames from a single host never trips it.
|   • The route throttle below keys on IP alone, which closes exactly that gap.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
