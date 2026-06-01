<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Event\DTO\EventTaxFeeBreakdownItemDTO;
use HiEvents\Services\Domain\Event\EventStatsFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EventStatsFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventStatsFetchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EventStatsFetchService::class);
    }

    /**
     * The per-name breakdown must:
     *  - include COMPLETED orders,
     *  - net off refunds proportionally for COMPLETED orders that were refunded
     *    (e.g. a partial refund, or a full refund issued directly in Stripe — both
     *    of which leave status = COMPLETED),
     *  - and exclude CANCELLED and non-completed (e.g. RESERVED) orders entirely.
     */
    public function test_breakdown_nets_off_refunds_and_excludes_cancelled_orders(): void
    {
        $eventId = $this->seedEvent();

        $rollup = [
            'taxes' => [['name' => 'VAT', 'rate' => 20, 'type' => 'PERCENTAGE', 'value' => 20]],
            'fees' => [['name' => 'Booking Fee', 'rate' => 2.5, 'type' => 'PERCENTAGE', 'value' => 10]],
        ];

        // 1. Completed, not refunded -> full value (VAT 20, Booking Fee 10)
        $this->createOrder($eventId, OrderStatus::COMPLETED->name, totalGross: 100, totalRefunded: 0, rollup: $rollup);

        // 2. Completed, partially refunded 50% -> half value (VAT 10, Booking Fee 5)
        $this->createOrder(
            $eventId,
            OrderStatus::COMPLETED->name,
            totalGross: 100,
            totalRefunded: 50,
            rollup: $rollup,
            refundStatus: OrderRefundStatus::PARTIALLY_REFUNDED->name,
        );

        // 3. Completed, fully refunded directly in Stripe (status stays COMPLETED) -> zero value
        $this->createOrder(
            $eventId,
            OrderStatus::COMPLETED->name,
            totalGross: 100,
            totalRefunded: 100,
            rollup: $rollup,
            refundStatus: OrderRefundStatus::REFUNDED->name,
        );

        // 4. Cancelled (the platform "cancel + refund" UX) -> excluded entirely
        $this->createOrder(
            $eventId,
            OrderStatus::CANCELLED->name,
            totalGross: 100,
            totalRefunded: 100,
            rollup: $rollup,
            refundStatus: OrderRefundStatus::REFUNDED->name,
        );

        // 5. Reserved (never paid) -> excluded entirely
        $this->createOrder($eventId, OrderStatus::RESERVED->name, totalGross: 100, totalRefunded: 0, rollup: $rollup);

        $breakdown = $this->service->getTaxAndFeeBreakdown($eventId);

        $vat = $this->itemFor($breakdown, 'TAX', 'VAT');
        $fee = $this->itemFor($breakdown, 'FEE', 'Booking Fee');

        // 20 (full) + 10 (half) + 0 (fully refunded) = 30. Cancelled & reserved excluded.
        $this->assertEqualsWithDelta(30.0, $vat->total_collected, 0.001);
        $this->assertEqualsWithDelta(15.0, $fee->total_collected, 0.001);

        // Only the three COMPLETED orders are counted (cancelled + reserved excluded).
        $this->assertSame(3, $vat->order_count);
        $this->assertSame(3, $fee->order_count);

        // No leakage of the cancelled order's gross (would be 50 / 25 if it were included).
        $this->assertLessThan(50.0, $vat->total_collected);
        $this->assertLessThan(25.0, $fee->total_collected);
    }

    public function test_breakdown_is_empty_when_event_has_no_completed_orders(): void
    {
        $eventId = $this->seedEvent();

        $this->createOrder($eventId, OrderStatus::CANCELLED->name, totalGross: 100, totalRefunded: 100, rollup: [
            'taxes' => [['name' => 'VAT', 'rate' => 20, 'type' => 'PERCENTAGE', 'value' => 20]],
            'fees' => [],
        ]);

        $this->assertCount(0, $this->service->getTaxAndFeeBreakdown($eventId));
    }

    /**
     * @param Collection<EventTaxFeeBreakdownItemDTO> $breakdown
     */
    private function itemFor(Collection $breakdown, string $kind, string $name): EventTaxFeeBreakdownItemDTO
    {
        $item = $breakdown->first(fn(EventTaxFeeBreakdownItemDTO $i) => $i->kind === $kind && $i->name === $name);

        $this->assertNotNull($item, "Expected a {$kind} breakdown item named {$name}");

        return $item;
    }

    private function seedEvent(): int
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
        $event->title = 'Tax Breakdown Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-TAXBD-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->start_date = Carbon::now()->addDays(7);
        $event->currency = 'USD';
        $event->save();

        return (int)$event->id;
    }

    private function createOrder(
        int     $eventId,
        string  $status,
        float   $totalGross,
        float   $totalRefunded,
        array   $rollup,
        ?string $refundStatus = null,
    ): Order
    {
        static $counter = 0;
        $counter++;

        $order = new Order();
        $order->event_id = $eventId;
        $order->public_id = 'ORD-TAXBD-' . $eventId . '-' . $counter;
        $order->short_id = 'ORD-TAXBD-' . $eventId . '-' . $counter;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = $status;
        $order->refund_status = $refundStatus;
        $order->currency = 'USD';
        $order->total_gross = $totalGross;
        $order->total_refunded = $totalRefunded;
        $order->taxes_and_fees_rollup = $rollup;
        $order->save();

        return $order;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
