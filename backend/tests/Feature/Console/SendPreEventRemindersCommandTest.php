<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Jobs\Event\SendEventReminderJob;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendPreEventRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_for_events_in_window(): void
    {
        Bus::fake();

        $eventId = $this->seedEvent(
            startInHours: 2,
            reminderHours: 24,
            enabled: true,
            status: EventStatus::LIVE->name,
        );

        $this->artisan('app:send-pre-event-reminders')->assertSuccessful();

        Bus::assertDispatched(SendEventReminderJob::class, function (SendEventReminderJob $job) use ($eventId) {
            return $this->getJobEventId($job) === $eventId;
        });
    }

    public function test_skips_events_starting_after_window(): void
    {
        Bus::fake();

        $this->seedEvent(
            startInHours: 48,
            reminderHours: 24,
            enabled: true,
            status: EventStatus::LIVE->name,
        );

        $this->artisan('app:send-pre-event-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendEventReminderJob::class);
    }

    public function test_skips_events_with_reminders_disabled(): void
    {
        Bus::fake();

        $this->seedEvent(
            startInHours: 2,
            reminderHours: 24,
            enabled: false,
            status: EventStatus::LIVE->name,
        );

        $this->artisan('app:send-pre-event-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendEventReminderJob::class);
    }

    public function test_skips_past_events(): void
    {
        Bus::fake();

        $this->seedEvent(
            startInHours: -2,
            reminderHours: 24,
            enabled: true,
            status: EventStatus::LIVE->name,
        );

        $this->artisan('app:send-pre-event-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendEventReminderJob::class);
    }

    public function test_skips_draft_events(): void
    {
        Bus::fake();

        $this->seedEvent(
            startInHours: 2,
            reminderHours: 24,
            enabled: true,
            status: EventStatus::DRAFT->name,
        );

        $this->artisan('app:send-pre-event-reminders')->assertSuccessful();

        Bus::assertNotDispatched(SendEventReminderJob::class);
    }

    private function seedEvent(
        int $startInHours,
        int $reminderHours,
        bool $enabled,
        string $status,
    ): int {
        $user = User::factory()->withAccount()->create();
        $this->actingAs($user);
        /** @var Account $account */
        $account = $user->accounts()->first();

        $organizer = new Organizer();
        $organizer->account_id = $account->id;
        $organizer->name = 'Organizer ' . uniqid();
        $organizer->email = 'org-' . uniqid() . '@example.com';
        $organizer->timezone = 'UTC';
        $organizer->save();

        $event = new Event();
        $event->account_id = $account->id;
        $event->user_id = $user->id;
        $event->organizer_id = $organizer->id;
        $event->title = 'Scheduler Test';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-' . uniqid();
        $event->status = $status;
        $event->start_date = Carbon::now()->addHours($startInHours);
        $event->currency = 'USD';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->support_email = 'support@example.com';
        $eventSetting->pre_event_reminder_enabled = $enabled;
        $eventSetting->pre_event_reminder_hours = $reminderHours;
        $eventSetting->save();

        return $event->id;
    }

    private function getJobEventId(SendEventReminderJob $job): int
    {
        $ref = new \ReflectionObject($job);
        $prop = $ref->getProperty('eventId');
        $prop->setAccessible(true);

        return (int) $prop->getValue($job);
    }
}
