<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A payment whose shopper never came back and whose webhook never arrived is
// found here, and an invoice that has run out releases its order. Needs the
// scheduler running — see docs/DEPLOYMENT.md.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();

// Emails are queued so a slow mail server never holds up a checkout. One short
// pass a minute drains them — no long-running worker to leak memory or to
// forget to restart after a deploy. (Nothing to do when mail is sent at once.)
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->when(fn () => config('queue.default') !== 'sync');

// A dated copy of the shop's database every night, kept for two weeks. See
// docs/DEPLOYMENT.md for getting a copy off the server.
Schedule::command('store:backup')->dailyAt('03:30')->withoutOverlapping(30);
