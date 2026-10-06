<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Due-soon / overdue reminders. Needs the usual cron entry: * * * * * php artisan schedule:run
Schedule::command('pmo:send-reminders')->dailyAt('08:00');
