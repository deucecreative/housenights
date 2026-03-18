<?php

namespace Tests\Feature\Jobs;

use HiEvents\Jobs\SendMetaConversionsApiEventJob;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMetaConversionsApiEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_correct_payload_with_hashed_pii()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

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
        $event->title = 'Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-123';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->meta_pixel_id = '123456789';
        $eventSetting->meta_conversions_api_access_token = 'access_token_123';
        $eventSetting->save();

        // Meta requires PII to be lowercase and whitespace stripped before hashing
        // We will provide slightly messy data to test the cleanup
        $order = new Order();
        $order->event_id = $event->id;
        $order->public_id = 'ORD-123456';
        $order->email = '  TEST.USER@Example.com ';
        $order->first_name = ' John  ';
        $order->last_name = '  Doe';
        $order->total_gross = 123.45;
        $order->currency = 'GBP';
        $order->short_id = 'ABCD-1234';
        $order->status = 'COMPLETED';
        $order->save();

        $job = new SendMetaConversionsApiEventJob($order->id);
        app()->call([$job, 'handle']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($order) {
            $this->assertStringContainsString('graph.facebook.com/v22.0/123456789/events?access_token=access_token_123', $request->url());

            $data = $request['data'];
            $this->assertCount(1, $data);
            $eventData = $data[0];

            $this->assertEquals('Purchase', $eventData['event_name']);
            $this->assertEquals($order->short_id, $eventData['event_id']);
            $this->assertEquals('website', $eventData['action_source']);

            // Expected Hashes
            $expectedEmailHash = hash('sha256', 'test.user@example.com');
            $expectedFnHash = hash('sha256', 'john');
            $expectedLnHash = hash('sha256', 'doe');

            $this->assertEquals($expectedEmailHash, $eventData['user_data']['em'][0]);
            $this->assertEquals($expectedFnHash, $eventData['user_data']['fn'][0]);
            $this->assertEquals($expectedLnHash, $eventData['user_data']['ln'][0]);
            if (isset($eventData['user_data']['client_ip_address'])) {
                $this->assertNotNull($eventData['user_data']['client_ip_address']);
            }

            $this->assertEquals(123.45, $eventData['custom_data']['value']);
            $this->assertEquals('GBP', $eventData['custom_data']['currency']);

            return true;
        });
    }

    public function test_it_does_not_send_request_if_token_is_missing()
    {
        Http::fake();

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
        $event->title = 'Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-456';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->meta_pixel_id = '123456789';
        // Missing token
        $eventSetting->save();

        $order = new Order();
        $order->event_id = $event->id;
        $order->public_id = 'ORD-654321';
        $order->email = 'test@example.com';
        $order->short_id = 'ABCD-1234';
        $order->status = 'COMPLETED';
        $order->currency = 'USD';
        $order->total_gross = 0;
        $order->save();

        $job = new SendMetaConversionsApiEventJob($order->id);
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }
}
