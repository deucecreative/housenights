<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Jobs\Event\SendEventReminderJob;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Order\OrderTicketsMail;
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
use Illuminate\Support\Facades\Mail;
use ReflectionObject;
use Tests\TestCase;

class SendEventReminderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_purchaser_email_with_reminder_flag(): void
    {
        Mail::fake();

        $ctx = $this->seedEventWithOrder(
            purchaserEmail: 'alice@example.com',
            attendeeEmails: ['alice@example.com'],
        );

        $job = new SendEventReminderJob($ctx['event_id']);
        app()->call([$job, 'handle']);

        Mail::assertQueued(OrderTicketsMail::class, function (OrderTicketsMail $mail) use ($ctx) {
            $isReminder = $this->getPrivate($mail, 'isReminder');
            $order = $this->getPrivate($mail, 'order');

            return $isReminder === true
                && $order !== null
                && $order->getId() === $ctx['order_id'];
        });

        $this->assertDatabaseHas('orders', [
            'id' => $ctx['order_id'],
        ]);
        $this->assertNotNull(
            Order::find($ctx['order_id'])->reminder_sent_at,
            'orders.reminder_sent_at should be set after dispatch',
        );
    }

    public function test_sends_attendee_email_for_non_purchaser_email(): void
    {
        Mail::fake();

        $ctx = $this->seedEventWithOrder(
            purchaserEmail: 'alice@example.com',
            attendeeEmails: ['alice@example.com', 'bob@example.com'],
        );

        $job = new SendEventReminderJob($ctx['event_id']);
        app()->call([$job, 'handle']);

        Mail::assertQueued(AttendeeTicketMail::class, 1);
        Mail::assertQueued(AttendeeTicketMail::class, function (AttendeeTicketMail $mail) {
            $attendee = $this->getPrivate($mail, 'attendee');
            $isReminder = $this->getPrivate($mail, 'isReminder');
            return $attendee?->getEmail() === 'bob@example.com' && $isReminder === true;
        });
    }

    public function test_dedupes_attendees_with_same_email(): void
    {
        Mail::fake();

        $ctx = $this->seedEventWithOrder(
            purchaserEmail: 'alice@example.com',
            attendeeEmails: ['alice@example.com', 'bob@example.com', 'bob@example.com'],
        );

        $job = new SendEventReminderJob($ctx['event_id']);
        app()->call([$job, 'handle']);

        Mail::assertQueued(AttendeeTicketMail::class, 1);

        // Both attendee rows for bob@example.com should have reminder_sent_at set.
        $bobReminders = Attendee::where('email', 'bob@example.com')
            ->whereNotNull('reminder_sent_at')
            ->count();
        $this->assertSame(2, $bobReminders);
    }

    public function test_does_not_send_when_already_sent(): void
    {
        Mail::fake();

        $ctx = $this->seedEventWithOrder(
            purchaserEmail: 'alice@example.com',
            attendeeEmails: ['alice@example.com', 'bob@example.com'],
        );

        // Mark everything as already sent.
        Order::where('id', $ctx['order_id'])->update(['reminder_sent_at' => Carbon::now()]);
        Attendee::where('order_id', $ctx['order_id'])->update(['reminder_sent_at' => Carbon::now()]);

        $job = new SendEventReminderJob($ctx['event_id']);
        app()->call([$job, 'handle']);

        Mail::assertNothingQueued();
    }

    /**
     * @param string[] $attendeeEmails
     * @return array{event_id:int, order_id:int}
     */
    private function seedEventWithOrder(string $purchaserEmail, array $attendeeEmails): array
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
        $event->title = 'Reminder Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-REM-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->start_date = Carbon::now()->addHours(2);
        $event->currency = 'USD';
        $event->save();

        $eventSetting = new EventSetting();
        $eventSetting->event_id = $event->id;
        $eventSetting->support_email = 'support@example.com';
        $eventSetting->pre_event_reminder_enabled = true;
        $eventSetting->pre_event_reminder_hours = 24;
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
        $order->public_id = 'ORD-' . $event->id;
        $order->short_id = 'ORD-' . $event->id;
        $order->email = $purchaserEmail;
        $order->first_name = 'Alice';
        $order->last_name = 'Buyer';
        $order->status = OrderStatus::COMPLETED->name;
        $order->currency = 'USD';
        $order->total_gross = 10;
        $order->save();

        foreach ($attendeeEmails as $index => $email) {
            $attendee = new Attendee();
            $attendee->order_id = $order->id;
            $attendee->event_id = $event->id;
            $attendee->product_id = $product->id;
            $attendee->product_price_id = $productPrice->id;
            $attendee->status = AttendeeStatus::ACTIVE->name;
            $attendee->first_name = 'Attendee';
            $attendee->last_name = (string) $index;
            $attendee->email = $email;
            $attendee->short_id = 'ATT-' . $event->id . '-' . $index;
            $attendee->public_id = 'pub-' . $event->id . '-' . $index;
            $attendee->save();
        }

        return [
            'event_id' => $event->id,
            'order_id' => $order->id,
        ];
    }

    private function getPrivate(object $obj, string $property): mixed
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }
}
