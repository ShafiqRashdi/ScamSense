<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | The retention purge runs on a schedule, not merely on demand — a command
 | nobody invokes deletes nothing, and the privacy notice would still be a
 | promise the system does not keep (security review finding 5).
 |
 | 03:15 rather than midnight: off the hour avoids the thundering herd of every
 | cron on the host firing together.
 */
Schedule::command('scamsense:purge')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();
