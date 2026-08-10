<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Phase 0 wires up only the two routes needed to prove the app boots. The
| public verification flow (O2) lands in Phase 6 and the admin area (O3) in
| Phase 8; both placeholders below say so on the page itself so that a
| screenshot can never be mistaken for a finished feature.
|
*/

Route::view('/', 'welcome')->name('home');

Route::view('/dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

require __DIR__.'/auth.php';
