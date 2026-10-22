<?php

use App\Domain\Platform\OperationsHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the server cron: * * * * * php artisan schedule:run
// Heartbeat read by /up and ops:check (OperationsHealth).
Schedule::call(fn () => app(OperationsHealth::class)->beat())->everyMinute()->name('ops-heartbeat');
Schedule::command('ops:check')->hourly()->withoutOverlapping();
Schedule::command('appointments:send-reminders')->hourly()->withoutOverlapping();
Schedule::command('admissions:send-visit-reminders')->hourly()->withoutOverlapping();
Schedule::command('library:run-notices')->hourly()->withoutOverlapping();
Schedule::command('documents:send-review-reminders')->hourly()->withoutOverlapping();

if (config('finance.auto_generate')) {
    Schedule::command('finance:generate-fee-invoices')->dailyAt('07:00')->timezone('Asia/Tbilisi')->withoutOverlapping();
}

// No supervisor on the CloudPanel host: drain the queue from the scheduler.
if (config('queue.scheduled_worker')) {
    Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground();
}
