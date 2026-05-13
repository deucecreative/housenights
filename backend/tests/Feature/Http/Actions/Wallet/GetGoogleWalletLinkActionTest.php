<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Wallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Account;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetGoogleWalletLinkActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->walletConfigured()) {
            $this->markTestSkipped(
                'Google Wallet is not configured in this environment '
                . '(check GOOGLE_WALLET_ISSUER_ID and the service account file/env).'
            );
        }
    }

    public function test_returns_save_link_for_active_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/' . $ctx['short_id'] . '/google-pass-link');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');

        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('url', $body);
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', (string)$body['url']);
    }

    public function test_returns_404_for_cancelled_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::CANCELLED->name);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/' . $ctx['short_id'] . '/google-pass-link');

        $response->assertStatus(404);
    }

    public function test_returns_404_for_unknown_short_id(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/totally-bogus-short-id/google-pass-link');

        $response->assertStatus(404);
    }

    /**
     * @return array{event_id:int, short_id:string}
     */
    private function seedAttendee(string $status): array
    {
        $user = User::factory()->withAccount()->create();
        $this->actingAs($user);
        /** @var Account $account */
        $account = $user->accounts()->first();

        $organizer = new Organizer();
        $organizer->account_id = $account->id;
        $organizer->name = 'Test Organizer';
        $organizer->email = 'organizer@example.com';
        $organizer->timezone = 'UTC';
        $organizer->save();

        $event = new Event();
        $event->account_id = $account->id;
        $event->user_id = $user->id;
        $event->organizer_id = $organizer->id;
        $event->title = 'Google Wallet Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-GWAL-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->start_date = Carbon::now()->addDays(7);
        $event->currency = 'USD';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->support_email = 'support@example.com';
        $eventSetting->save();

        $product = new Product();
        $product->event_id = $event->id;
        $product->title = 'GA';
        $product->order = 1;
        $product->save();

        $productPrice = new ProductPrice();
        $productPrice->product_id = $product->id;
        $productPrice->price = 10;
        $productPrice->save();

        $order = new Order();
        $order->event_id = $event->id;
        $order->public_id = 'ORD-GWAL-' . $event->id;
        $order->short_id = 'ORD-GWAL-' . $event->id;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = OrderStatus::COMPLETED->name;
        $order->currency = 'USD';
        $order->total_gross = 10;
        $order->save();

        $shortId = 'ATT-GWAL-' . $event->id;

        $attendee = new Attendee();
        $attendee->order_id = $order->id;
        $attendee->event_id = $event->id;
        $attendee->product_id = $product->id;
        $attendee->product_price_id = $productPrice->id;
        $attendee->status = $status;
        $attendee->first_name = 'Wally';
        $attendee->last_name = 'Holder';
        $attendee->email = 'wally@example.com';
        $attendee->short_id = $shortId;
        $attendee->public_id = 'pub-gwal-' . $event->id;
        $attendee->save();

        return [
            'event_id' => (int)$event->id,
            'short_id' => $shortId,
        ];
    }

    private function walletConfigured(): bool
    {
        $issuerId = (string)config('wallet.google.issuer_id');
        if ($issuerId === '') {
            return false;
        }

        $b64 = (string)config('wallet.google.service_account_b64');
        if ($b64 !== '') {
            return true;
        }

        $path = (string)config('wallet.google.service_account_path');
        if ($path === '') {
            return false;
        }
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        return is_file($resolved);
    }
}
