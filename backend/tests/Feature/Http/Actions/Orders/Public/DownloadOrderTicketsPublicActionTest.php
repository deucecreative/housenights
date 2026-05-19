<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Orders\Public;

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

class DownloadOrderTicketsPublicActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloads_pdf_for_completed_order(): void
    {
        $ctx = $this->seedOrder(
            orderStatus: OrderStatus::COMPLETED->name,
            attendeeStatuses: [AttendeeStatus::ACTIVE->name, AttendeeStatus::ACTIVE->name],
        );

        $response = $this->get(sprintf(
            '/public/events/%d/order/%s/tickets.pdf',
            $ctx['event_id'],
            $ctx['order_short_id'],
        ));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="tickets-',
            (string)$response->headers->get('Content-Disposition'),
        );
        $body = (string)$response->getContent();
        $this->assertSame('%PDF-', substr($body, 0, 5));
    }

    public function test_returns_404_for_non_completed_order(): void
    {
        $ctx = $this->seedOrder(
            orderStatus: OrderStatus::RESERVED->name,
            attendeeStatuses: [AttendeeStatus::ACTIVE->name],
        );

        $response = $this->get(sprintf(
            '/public/events/%d/order/%s/tickets.pdf',
            $ctx['event_id'],
            $ctx['order_short_id'],
        ));

        $response->assertStatus(404);
    }

    public function test_returns_404_for_unknown_order_short_id(): void
    {
        $ctx = $this->seedOrder(
            orderStatus: OrderStatus::COMPLETED->name,
            attendeeStatuses: [AttendeeStatus::ACTIVE->name],
        );

        $response = $this->get(sprintf(
            '/public/events/%d/order/%s/tickets.pdf',
            $ctx['event_id'],
            'not-a-real-order',
        ));

        $response->assertStatus(404);
    }

    public function test_returns_404_when_order_has_no_active_attendees(): void
    {
        $ctx = $this->seedOrder(
            orderStatus: OrderStatus::COMPLETED->name,
            attendeeStatuses: [AttendeeStatus::CANCELLED->name],
        );

        $response = $this->get(sprintf(
            '/public/events/%d/order/%s/tickets.pdf',
            $ctx['event_id'],
            $ctx['order_short_id'],
        ));

        $response->assertStatus(404);
    }

    public function test_pdf_download_works_when_wallet_disabled(): void
    {
        $ctx = $this->seedOrder(
            orderStatus: OrderStatus::COMPLETED->name,
            attendeeStatuses: [AttendeeStatus::ACTIVE->name],
            walletPassesEnabled: false,
        );

        $response = $this->get(sprintf(
            '/public/events/%d/order/%s/tickets.pdf',
            $ctx['event_id'],
            $ctx['order_short_id'],
        ));

        $response->assertOk();
        $this->assertSame('%PDF-', substr((string)$response->getContent(), 0, 5));
    }

    /**
     * @param string[] $attendeeStatuses
     * @return array{event_id:int, order_short_id:string}
     */
    private function seedOrder(
        string $orderStatus,
        array $attendeeStatuses,
        bool $walletPassesEnabled = true,
    ): array {
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
        $event->title = 'Order PDF Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-OPDF-' . $organizer->id;
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

        $orderShortId = 'ORD-OPDF-' . $event->id;

        $order = new Order();
        $order->event_id = $event->id;
        $order->public_id = 'ORD-OPDF-' . $event->id;
        $order->short_id = $orderShortId;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = $orderStatus;
        $order->currency = 'USD';
        $order->total_gross = 10;
        $order->save();

        foreach ($attendeeStatuses as $index => $status) {
            $attendee = new Attendee();
            $attendee->order_id = $order->id;
            $attendee->event_id = $event->id;
            $attendee->product_id = $product->id;
            $attendee->product_price_id = $productPrice->id;
            $attendee->status = $status;
            $attendee->first_name = 'Attendee' . $index;
            $attendee->last_name = 'Test';
            $attendee->email = sprintf('att%d@example.com', $index);
            $attendee->short_id = sprintf('ATT-OPDF-%d-%d', $event->id, $index);
            $attendee->public_id = sprintf('pub-opdf-%d-%d', $event->id, $index);
            $attendee->save();
        }

        auth()->logout();

        return [
            'event_id' => (int)$event->id,
            'order_short_id' => $orderShortId,
        ];
    }
}
