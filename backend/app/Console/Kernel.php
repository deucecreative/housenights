<?php

namespace HiEvents\Console;

use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->command('app:send-pre-event-reminders')->hourly()->withoutOverlapping();

        // Nightly reconcile of event statistics from order data (heals incremental drift,
        // e.g. cancellations that didn't decrement financial totals).
        $schedule->command('stats:recompute')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        include base_path('routes/console.php');
    }
}
