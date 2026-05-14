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

class DownloadApplePassActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->walletConfigured()) {
            $this->markTestSkipped(
                'Apple Wallet certificates are not configured in this environment '
                . '(check APPLE_WALLET_CERT_PATH / APPLE_WALLET_WWDR_PATH / APPLE_WALLET_CERT_PASSWORD).'
            );
        }
    }

    public function test_downloads_pkpass_for_active_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name, walletPassesEnabled: true);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/' . $ctx['short_id'] . '/apple-pass');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.apple.pkpass',
            $response->headers->get('Content-Type'),
            'Apple Wallet pass download must use the application/vnd.apple.pkpass MIME type'
        );

        $body = $response->getContent();
        $this->assertNotEmpty($body);
        $this->assertSame("\x50\x4b\x03\x04", substr((string)$body, 0, 4), 'Body must start with ZIP "PK" magic bytes');
    }

    public function test_returns_404_for_non_active_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::CANCELLED->name, walletPassesEnabled: true);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/' . $ctx['short_id'] . '/apple-pass');

        $response->assertStatus(404);
    }

    public function test_returns_404_for_unknown_short_id(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name, walletPassesEnabled: true);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/totally-bogus-short-id/apple-pass');

        $response->assertStatus(404);
    }

    public function test_returns_404_when_wallet_disabled_for_event(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name, walletPassesEnabled: false);

        $response = $this->get('/public/attendee/' . $ctx['event_id'] . '/' . $ctx['short_id'] . '/apple-pass');

        $response->assertStatus(404);
    }

    /**
     * @return array{event_id:int, short_id:string}
     */
    private function seedAttendee(string $status, bool $walletPassesEnabled = false): array
    {
        $user = User::factory()->withAccount()->create();
        // actingAs for seed phase only — Event::creating hook reads auth()->user()->id.
        // We log out before the HTTP request because the wallet endpoints are PUBLIC
        // and SetAccountContext middleware blows up parsing a non-existent JWT token
        // when a Laravel session user is set without a corresponding JWT.
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
        $event->title = 'Wallet Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-WAL-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->start_date = Carbon::now()->addDays(7);
        $event->currency = 'USD';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->support_email = 'support@example.com';
        $eventSetting->wallet_passes_enabled = $walletPassesEnabled;
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
        $order->public_id = 'ORD-WAL-' . $event->id;
        $order->short_id = 'ORD-WAL-' . $event->id;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = OrderStatus::COMPLETED->name;
        $order->currency = 'USD';
        $order->total_gross = 10;
        $order->save();

        $shortId = 'ATT-WAL-' . $event->id;

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
        $attendee->public_id = 'pub-wal-' . $event->id;
        $attendee->save();

        // Log out before HTTP request to mimic an unauthenticated public visitor.
        auth()->logout();

        return [
            'event_id' => (int)$event->id,
            'short_id' => $shortId,
        ];
    }

    private function walletConfigured(): bool
    {
        $passType = (string)config('mobile-pass.apple.type_identifier');
        if ($passType === '') {
            return false;
        }

        $b64 = (string)config('mobile-pass.apple.certificate');
        $certPath = (string)config('mobile-pass.apple.certificate_path');
        $password = (string)config('mobile-pass.apple.certificate_password');

        if ($b64 !== '') {
            $certBytes = base64_decode($b64, true);
        } elseif ($certPath !== '') {
            $resolved = str_starts_with($certPath, '/') ? $certPath : base_path($certPath);
            $certBytes = is_file($resolved) ? file_get_contents($resolved) : false;
        } else {
            return false;
        }

        if ($certBytes === false) {
            return false;
        }

        // Verify the password actually matches the .p12 (skip rather than fail
        // when local .env has a rotated password mismatch).
        return (bool)@openssl_pkcs12_read($certBytes, $unused, $password);
    }
}
