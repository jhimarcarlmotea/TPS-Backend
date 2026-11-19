<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatically mark approved appointments as completed after their end time (Asia/Manila).
Schedule::command('appointments:complete-ended')
    ->everyMinute()
    ->timezone('Asia/Manila');

Schedule::command('appointments:send-reminders')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

Schedule::command('backups:run-scheduled')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
