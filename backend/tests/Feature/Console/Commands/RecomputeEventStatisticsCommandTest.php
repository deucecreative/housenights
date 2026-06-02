<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Account;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecomputeEventStatisticsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recompute_corrects_gross_excludes_cancelled_and_nets_refunds(): void
    {
        [$eventId, $product, $productPrice] = $this->seedEvent();

        // A: completed, no refund -> gross 100, tax 20, fee 10, before 70, qty 2, 2 active attendees
        $orderA = $this->createOrder($eventId, OrderStatus::COMPLETED->name, gross: 100, refunded: 0, tax: 20, fee: 10, before: 70);
        $this->createOrderItem($orderA, $product, $productPrice, quantity: 2);
        $this->createAttendees($orderA, $eventId, $product, $productPrice, AttendeeStatus::ACTIVE->name, 2);

        // B: cancelled -> contributes only orders_cancelled; financials/products/attendees excluded
        $orderB = $this->createOrder($eventId, OrderStatus::CANCELLED->name, gross: 50, refunded: 0, tax: 10, fee: 5, before: 35);
        $this->createOrderItem($orderB, $product, $productPrice, quantity: 1);
        $this->createAttendees($orderB, $eventId, $product, $productPrice, AttendeeStatus::CANCELLED->name, 2);

        // C: completed, 50% refunded -> gross 50 net, tax 10, fee 5, before 80, qty 1, 1 active attendee
        $orderC = $this->createOrder($eventId, OrderStatus::COMPLETED->name, gross: 100, refunded: 50, tax: 20, fee: 10, before: 80);
        $this->createOrderItem($orderC, $product, $productPrice, quantity: 1);
        $this->createAttendees($orderC, $eventId, $product, $productPrice, AttendeeStatus::ACTIVE->name, 1);

        // Pre-existing, WRONG aggregate row (simulating drift) with view counters that must be preserved.
        DB::table('event_statistics')->insert([
            'event_id' => $eventId,
            'sales_total_gross' => 999, 'sales_total_before_additions' => 999,
            'total_tax' => 999, 'total_fee' => 999, 'total_refunded' => 0,
            'products_sold' => 99, 'attendees_registered' => 99,
            'orders_created' => 5, 'orders_cancelled' => 0,
            'total_views' => 999, 'unique_views' => 42, 'version' => 7,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        Artisan::call('stats:recompute', ['--event' => (string)$eventId]);

        $stats = DB::table('event_statistics')->where('event_id', $eventId)->first();

        $this->assertEqualsWithDelta(150.0, (float)$stats->sales_total_gross, 0.001);          // 100 + (100-50)
        $this->assertEqualsWithDelta(150.0, (float)$stats->sales_total_before_additions, 0.001); // 70 + 80 (not refund-adjusted)
        $this->assertEqualsWithDelta(50.0, (float)$stats->total_refunded, 0.001);
        $this->assertEqualsWithDelta(30.0, (float)$stats->total_tax, 0.001);                    // 20 + 20*0.5
        $this->assertEqualsWithDelta(15.0, (float)$stats->total_fee, 0.001);                    // 10 + 10*0.5
        $this->assertSame(2, (int)$stats->orders_created);
        $this->assertSame(1, (int)$stats->orders_cancelled);
        $this->assertSame(3, (int)$stats->products_sold);                                       // 2 + 1 (cancelled excluded)
        $this->assertSame(3, (int)$stats->attendees_registered);                               // 2 + 1 (cancelled excluded)

        // View counters are not order-derived and must be preserved; version bumped.
        $this->assertSame(999, (int)$stats->total_views);
        $this->assertSame(42, (int)$stats->unique_views);
        $this->assertSame(8, (int)$stats->version);

        // Daily row(s) reconcile to the same totals (all orders share today's date).
        $daily = DB::table('event_daily_statistics')->where('event_id', $eventId)->get();
        $this->assertEqualsWithDelta(150.0, (float)$daily->sum('sales_total_gross'), 0.001);
        $this->assertEqualsWithDelta(30.0, (float)$daily->sum('total_tax'), 0.001);
        $this->assertEqualsWithDelta(15.0, (float)$daily->sum('total_fee'), 0.001);
        $this->assertSame(1, (int)$daily->sum('orders_cancelled'));
        $this->assertSame(3, (int)$daily->sum('products_sold'));
    }

    public function test_dry_run_makes_no_changes(): void
    {
        [$eventId, $product, $productPrice] = $this->seedEvent();
        $order = $this->createOrder($eventId, OrderStatus::COMPLETED->name, gross: 100, refunded: 0, tax: 20, fee: 10, before: 70);
        $this->createOrderItem($order, $product, $productPrice, quantity: 1);

        DB::table('event_statistics')->insert([
            'event_id' => $eventId, 'sales_total_gross' => 999,
            'total_views' => 5, 'version' => 1,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        Artisan::call('stats:recompute', ['--event' => (string)$eventId, '--dry-run' => true]);

        $stats = DB::table('event_statistics')->where('event_id', $eventId)->first();
        $this->assertEqualsWithDelta(999.0, (float)$stats->sales_total_gross, 0.001); // unchanged
    }

    private function seedEvent(): array
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
        $event->title = 'Recompute Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-RECMP-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->start_date = Carbon::now()->addDays(7);
        $event->currency = 'USD';
        $event->save();

        $product = new Product();
        $product->event_id = $event->id;
        $product->title = 'GA';
        $product->order = 1;
        $product->save();

        $productPrice = new ProductPrice();
        $productPrice->product_id = $product->id;
        $productPrice->price = 10;
        $productPrice->save();

        return [(int)$event->id, $product, $productPrice];
    }

    private function createOrder(int $eventId, string $status, float $gross, float $refunded, float $tax, float $fee, float $before): Order
    {
        static $n = 0;
        $n++;

        $order = new Order();
        $order->event_id = $eventId;
        $order->public_id = 'ORD-RECMP-' . $eventId . '-' . $n;
        $order->short_id = 'ORD-RECMP-' . $eventId . '-' . $n;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = $status;
        $order->currency = 'USD';
        $order->total_gross = $gross;
        $order->total_refunded = $refunded;
        $order->total_tax = $tax;
        $order->total_fee = $fee;
        $order->total_before_additions = $before;
        $order->save();

        return $order;
    }

    private function createOrderItem(Order $order, Product $product, ProductPrice $productPrice, int $quantity): void
    {
        $item = new OrderItem();
        $item->order_id = $order->id;
        $item->product_id = $product->id;
        $item->product_price_id = $productPrice->id;
        $item->quantity = $quantity;
        $item->price = 10;
        $item->total_before_additions = 10 * $quantity;
        $item->total_tax = 0;
        $item->total_gross = 10 * $quantity;
        $item->total_service_fee = 0;
        $item->item_name = 'GA';
        $item->save();
    }

    private function createAttendees(Order $order, int $eventId, Product $product, ProductPrice $productPrice, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $attendee = new Attendee();
            $attendee->order_id = $order->id;
            $attendee->event_id = $eventId;
            $attendee->product_id = $product->id;
            $attendee->product_price_id = $productPrice->id;
            $attendee->status = $status;
            $attendee->first_name = 'Att';
            $attendee->last_name = (string)$i;
            $attendee->email = sprintf('att%d-%d@example.com', $order->id, $i);
            $attendee->short_id = sprintf('ATT-RECMP-%d-%d', $order->id, $i);
            $attendee->public_id = sprintf('pub-recmp-%d-%d', $order->id, $i);
            $attendee->save();
        }
    }
}
