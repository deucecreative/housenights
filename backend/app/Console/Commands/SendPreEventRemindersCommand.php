<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Jobs\Event\SendEventReminderJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SendPreEventRemindersCommand extends Command
{
    protected $signature = 'app:send-pre-event-reminders';

    protected $description = 'Dispatch pre-event reminder emails for events starting soon';

    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();

        // Find live events with pre_event_reminder_enabled where the event starts
        // within the configured per-event window (event_settings.pre_event_reminder_hours).
        $rows = DB::table('events')
            ->join('event_settings', 'event_settings.event_id', '=', 'events.id')
            ->whereNull('events.deleted_at')
            ->where('events.status', EventStatus::LIVE->name)
            ->where('event_settings.pre_event_reminder_enabled', true)
            ->where('events.start_date', '>=', $now)
            ->whereRaw(
                "events.start_date <= (? ::timestamp + (event_settings.pre_event_reminder_hours || ' hours')::interval)",
                [$now->toDateTimeString()],
            )
            ->select('events.id')
            ->get();

        foreach ($rows as $row) {
            dispatch(new SendEventReminderJob((int) $row->id));
        }

        $count = $rows->count();

        $this->logger->info('Dispatched pre-event reminder jobs', [
            'event_count' => $count,
        ]);

        $this->info("Dispatched {$count} pre-event reminder job(s).");

        return self::SUCCESS;
    }
}
