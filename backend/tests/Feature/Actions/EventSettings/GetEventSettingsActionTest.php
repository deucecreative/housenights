<?php

namespace Tests\Feature\Actions\EventSettings;

use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetEventSettingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_meta_tracking_fields_in_event_settings(): void
    {
        $user = User::factory()->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();

        $organizer = new Organizer();
        $organizer->account_id = $account->id;
        $organizer->name = 'Test Organizer';
        $organizer->email = 'organizer@test.com';
        $organizer->timezone = 'UTC';
        $organizer->save();

        $event = new Event();
        $event->account_id = $account->id;
        $event->organizer_id = $organizer->id;
        $event->user_id = $user->id;
        $event->title = 'Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-456';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->meta_pixel_id = '123456789';
        $eventSetting->meta_conversions_api_access_token = 'ABCdef1234567890';
        $eventSetting->save();

        $repository = app(\HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface::class);
        $settings = $repository->findFirstWhere(['event_id' => $event->id]);

        $this->assertNotNull($settings);
        $this->assertEquals('123456789', $settings->getMetaPixelId());
        $this->assertEquals('ABCdef1234567890', $settings->getMetaConversionsApiAccessToken());

        $resource = new \HiEvents\Resources\Event\EventSettingsResource($settings);
        $data = $resource->toArray(request());

        $this->assertArrayHasKey('meta_pixel_id', $data);
        $this->assertEquals('123456789', $data['meta_pixel_id']);
        $this->assertArrayHasKey('meta_conversions_api_access_token', $data);
        $this->assertEquals('ABCdef1234567890', $data['meta_conversions_api_access_token']);
    }

    public function test_it_returns_pre_event_reminder_fields_in_event_settings(): void
    {
        $user = User::factory()->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();

        $organizer = new Organizer();
        $organizer->account_id = $account->id;
        $organizer->name = 'Test Organizer';
        $organizer->email = 'organizer2@test.com';
        $organizer->timezone = 'UTC';
        $organizer->save();

        $event = new Event();
        $event->account_id = $account->id;
        $event->organizer_id = $organizer->id;
        $event->user_id = $user->id;
        $event->title = 'Test Event Reminder';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-REM';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->pre_event_reminder_enabled = false;
        $eventSetting->pre_event_reminder_hours = 48;
        $eventSetting->save();

        $repository = app(\HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface::class);
        $settings = $repository->findFirstWhere(['event_id' => $event->id]);

        $this->assertNotNull($settings);
        $this->assertFalse($settings->getPreEventReminderEnabled());
        $this->assertEquals(48, $settings->getPreEventReminderHours());

        $resource = new \HiEvents\Resources\Event\EventSettingsResource($settings);
        $data = $resource->toArray(request());

        $this->assertArrayHasKey('pre_event_reminder_enabled', $data);
        $this->assertFalse($data['pre_event_reminder_enabled']);
        $this->assertArrayHasKey('pre_event_reminder_hours', $data);
        $this->assertEquals(48, $data['pre_event_reminder_hours']);
    }
}
