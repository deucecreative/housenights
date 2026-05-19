<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Attendee\Public;

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

class DownloadAttendeeTicketPublicActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloads_pdf_for_active_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name);

        $response = $this->get(sprintf(
            '/public/events/%d/attendees/%s/ticket.pdf',
            $ctx['event_id'],
            $ctx['attendee_short_id'],
        ));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="ticket-',
            (string)$response->headers->get('Content-Disposition'),
        );
        $body = (string)$response->getContent();
        $this->assertSame('%PDF-', substr($body, 0, 5), 'Body must begin with %PDF- magic bytes');
    }

    public function test_returns_404_for_cancelled_attendee(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::CANCELLED->name);

        $response = $this->get(sprintf(
            '/public/events/%d/attendees/%s/ticket.pdf',
            $ctx['event_id'],
            $ctx['attendee_short_id'],
        ));

        $response->assertStatus(404);
    }

    public function test_returns_404_for_unknown_short_id(): void
    {
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name);

        $response = $this->get(sprintf(
            '/public/events/%d/attendees/%s/ticket.pdf',
            $ctx['event_id'],
            'totally-bogus-short-id',
        ));

        $response->assertStatus(404);
    }

    public function test_returns_404_when_short_id_belongs_to_different_event(): void
    {
        // Same short_id from one event must not be redeemable against a different event.
        $first = $this->seedAttendee(AttendeeStatus::ACTIVE->name);
        $second = $this->seedAttendee(AttendeeStatus::ACTIVE->name);

        $response = $this->get(sprintf(
            '/public/events/%d/attendees/%s/ticket.pdf',
            $second['event_id'],
            $first['attendee_short_id'],
        ));

        $response->assertStatus(404);
    }

    public function test_pdf_download_works_when_wallet_disabled(): void
    {
        // PDF download is independent of the wallet_passes_enabled flag.
        $ctx = $this->seedAttendee(AttendeeStatus::ACTIVE->name, walletPassesEnabled: false);

        $response = $this->get(sprintf(
            '/public/events/%d/attendees/%s/ticket.pdf',
            $ctx['event_id'],
            $ctx['attendee_short_id'],
        ));

        $response->assertOk();
        $this->assertSame('%PDF-', substr((string)$response->getContent(), 0, 5));
    }

    /**
     * @return array{event_id:int, attendee_short_id:string}
     */
    private function seedAttendee(string $status, bool $walletPassesEnabled = true): array
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
        $event->title = 'Ticket PDF Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-TPDF-' . $organizer->id;
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
        $order->public_id = 'ORD-TPDF-' . $event->id;
        $order->short_id = 'ORD-TPDF-' . $event->id;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = OrderStatus::COMPLETED->name;
        $order->currency = 'USD';
        $order->total_gross = 10;
        $order->save();

        $shortId = 'ATT-TPDF-' . $event->id;

        $attendee = new Attendee();
        $attendee->order_id = $order->id;
        $attendee->event_id = $event->id;
        $attendee->product_id = $product->id;
        $attendee->product_price_id = $productPrice->id;
        $attendee->status = $status;
        $attendee->first_name = 'Tess';
        $attendee->last_name = 'Holder';
        $attendee->email = 'tess@example.com';
        $attendee->short_id = $shortId;
        $attendee->public_id = 'pub-tpdf-' . $event->id;
        $attendee->save();

        // Log out so the public endpoint sees an unauthenticated visitor.
        auth()->logout();

        return [
            'event_id' => (int)$event->id,
            'attendee_short_id' => $shortId,
        ];
    }
}
