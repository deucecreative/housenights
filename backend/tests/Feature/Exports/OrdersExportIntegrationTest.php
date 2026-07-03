<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exports\OrdersExport;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Account;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\Question;
use HiEvents\Models\QuestionAnswer;
use HiEvents\Models\User;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OrdersExportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_emits_one_row_per_purchased_unit_with_answers(): void
    {
        $ctx = $this->seedOrderWithItemsAndAnswers();

        $orders = app(OrderRepositoryInterface::class)
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->loadRelation(QuestionAndAnswerViewDomainObject::class)
            ->loadRelation(new Relationship(AffiliateDomainObject::class, name: 'affiliate'))
            ->findByEventId($ctx['event_id'], new QueryParamsDTO(page: 1, per_page: 100));

        $orderQuestions = app(QuestionRepositoryInterface::class)->findWhere([
            'event_id' => $ctx['event_id'],
            'belongs_to' => QuestionBelongsTo::ORDER->name,
        ]);
        $productQuestions = app(QuestionRepositoryInterface::class)->findWhere([
            'event_id' => $ctx['event_id'],
            'belongs_to' => QuestionBelongsTo::PRODUCT->name,
        ]);

        $export = app(OrdersExport::class)->withData($orders, $orderQuestions, $productQuestions);

        $rows = $export->collection()
            ->flatMap(fn($order) => $export->map($order))
            ->values();

        $this->assertCount(3, $rows);

        $headings = $export->headings();
        $headingCount = count($headings);
        foreach ($rows as $row) {
            $this->assertCount($headingCount, $row);
        }

        $rowsByAttendeeEmail = $rows->keyBy(fn($row) => $row[array_search('Attendee Email', $headings, true)]);
        $this->assertArrayHasKey('alice@example.com', $rowsByAttendeeEmail->toArray());
        $this->assertArrayHasKey('bob@example.com', $rowsByAttendeeEmail->toArray());

        $quantityIndex = array_search('Quantity', $headings, true);
        $productNameIndex = array_search('Product Name', $headings, true);
        $orderAnswerIndex = array_search('How did you hear about us?', $headings, true);
        $productAnswerIndex = array_search('T-shirt size', $headings, true);

        $aliceRow = $rowsByAttendeeEmail['alice@example.com'];
        $this->assertSame(1, $aliceRow[$quantityIndex]);
        $this->assertSame('General Admission', $aliceRow[$productNameIndex]);
        $this->assertSame('A friend', $aliceRow[$orderAnswerIndex]);
        $this->assertSame('Large', $aliceRow[$productAnswerIndex]);

        $bobRow = $rowsByAttendeeEmail['bob@example.com'];
        $this->assertSame('A friend', $bobRow[$orderAnswerIndex]);
        $this->assertSame('', $bobRow[$productAnswerIndex]);

        $generalRow = $rowsByAttendeeEmail[''];
        $this->assertSame(3, $generalRow[$quantityIndex]);
        $this->assertSame('Poster', $generalRow[$productNameIndex]);
        $this->assertSame('A friend', $generalRow[$orderAnswerIndex]);
        $this->assertSame('', $generalRow[$productAnswerIndex]);

        // Generate the actual spreadsheet to exercise Laravel Excel's
        // multi-row map() handling, not just map() in isolation.
        $xlsx = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        $tempFile = tempnam(sys_get_temp_dir(), 'orders-export-test');
        file_put_contents($tempFile, $xlsx);

        try {
            $sheet = IOFactory::load($tempFile)->getActiveSheet();
            // 1 heading row + 3 data rows
            $this->assertSame(4, $sheet->getHighestDataRow());
            $this->assertSame('Order ID', $sheet->getCell('A1')->getValue());
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @return array{event_id:int}
     */
    private function seedOrderWithItemsAndAnswers(): array
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
        $event->title = 'Orders Export Test Event';
        $event->timezone = 'UTC';
        $event->short_id = 'EVENT-OEXP-' . $organizer->id;
        $event->status = EventStatus::LIVE->name;
        $event->currency = 'USD';
        $event->save();

        $ticketProduct = new Product();
        $ticketProduct->event_id = $event->id;
        $ticketProduct->title = 'General Admission';
        $ticketProduct->order = 1;
        $ticketProduct->save();

        $ticketPrice = new ProductPrice();
        $ticketPrice->product_id = $ticketProduct->id;
        $ticketPrice->price = 25;
        $ticketPrice->save();

        $generalProduct = new Product();
        $generalProduct->event_id = $event->id;
        $generalProduct->title = 'Poster';
        $generalProduct->order = 2;
        $generalProduct->save();

        $generalPrice = new ProductPrice();
        $generalPrice->product_id = $generalProduct->id;
        $generalPrice->price = 10;
        $generalPrice->save();

        $order = new Order();
        $order->event_id = $event->id;
        $order->public_id = 'ORD-OEXP-' . $event->id;
        $order->short_id = 'ord-oexp-' . $event->id;
        $order->email = 'buyer@example.com';
        $order->first_name = 'Buyer';
        $order->last_name = 'One';
        $order->status = OrderStatus::COMPLETED->name;
        $order->currency = 'USD';
        $order->total_before_additions = 80;
        $order->total_gross = 80;
        $order->save();

        $ticketItem = new OrderItem();
        $ticketItem->order_id = $order->id;
        $ticketItem->product_id = $ticketProduct->id;
        $ticketItem->product_price_id = $ticketPrice->id;
        $ticketItem->product_type = ProductType::TICKET->name;
        $ticketItem->item_name = 'General Admission';
        $ticketItem->quantity = 2;
        $ticketItem->price = 25;
        $ticketItem->total_before_additions = 50;
        $ticketItem->total_gross = 50;
        $ticketItem->save();

        $generalItem = new OrderItem();
        $generalItem->order_id = $order->id;
        $generalItem->product_id = $generalProduct->id;
        $generalItem->product_price_id = $generalPrice->id;
        $generalItem->product_type = ProductType::GENERAL->name;
        $generalItem->item_name = 'Poster';
        $generalItem->quantity = 3;
        $generalItem->price = 10;
        $generalItem->total_before_additions = 30;
        $generalItem->total_gross = 30;
        $generalItem->save();

        $attendees = [];
        foreach (['alice', 'bob'] as $index => $name) {
            $attendee = new Attendee();
            $attendee->order_id = $order->id;
            $attendee->event_id = $event->id;
            $attendee->product_id = $ticketProduct->id;
            $attendee->product_price_id = $ticketPrice->id;
            $attendee->status = AttendeeStatus::ACTIVE->name;
            $attendee->first_name = ucfirst($name);
            $attendee->last_name = 'Attendee';
            $attendee->email = $name . '@example.com';
            $attendee->short_id = sprintf('att-oexp-%d-%d', $event->id, $index);
            $attendee->public_id = sprintf('A-OEXP-%d-%d', $event->id, $index);
            $attendee->save();
            $attendees[$name] = $attendee;
        }

        $orderQuestion = new Question();
        $orderQuestion->event_id = $event->id;
        $orderQuestion->title = 'How did you hear about us?';
        $orderQuestion->belongs_to = QuestionBelongsTo::ORDER->name;
        $orderQuestion->type = QuestionTypeEnum::SINGLE_LINE_TEXT->name;
        $orderQuestion->save();

        $productQuestion = new Question();
        $productQuestion->event_id = $event->id;
        $productQuestion->title = 'T-shirt size';
        $productQuestion->belongs_to = QuestionBelongsTo::PRODUCT->name;
        $productQuestion->type = QuestionTypeEnum::SINGLE_LINE_TEXT->name;
        $productQuestion->save();

        QuestionAnswer::create([
            'question_id' => $orderQuestion->id,
            'order_id' => $order->id,
            'answer' => 'A friend',
        ]);

        QuestionAnswer::create([
            'question_id' => $productQuestion->id,
            'order_id' => $order->id,
            'attendee_id' => $attendees['alice']->id,
            'product_id' => $ticketProduct->id,
            'answer' => 'Large',
        ]);

        auth()->logout();

        return [
            'event_id' => (int)$event->id,
        ];
    }
}
