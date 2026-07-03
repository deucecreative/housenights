<?php

declare(strict_types=1);

namespace Tests\Unit\Exports;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Exports\OrdersExport;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrdersExportTest extends TestCase
{
    private const COL_ORDER_ID = 0;
    private const COL_PROMO_CODE = 22;
    private const COL_EVENT_ID = 26;
    private const COL_ORDER_ITEM_ID = 27;
    private const COL_PRODUCT_TYPE = 30;
    private const COL_PRODUCT_NAME = 31;
    private const COL_QUANTITY = 32;
    private const COL_UNIT_PRICE = 33;
    private const COL_UNIT_PRICE_BEFORE_DISCOUNT = 34;
    private const COL_ITEM_TOTAL_GROSS = 38;
    private const COL_ATTENDEE_ID = 40;
    private const COL_ATTENDEE_FIRST_NAME = 42;
    private const COL_ATTENDEE_EMAIL = 44;

    private OrdersExport $export;

    protected function setUp(): void
    {
        parent::setUp();
        $this->export = new OrdersExport(new QuestionAnswerFormatter());
    }

    public function test_ticket_items_expand_to_one_row_per_attendee_and_general_items_to_one_row(): void
    {
        $order = $this->buildOrder()
            ->setOrderItems(new Collection([
                $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'General Admission', quantity: 2, price: 25.0),
                $this->buildItem(id: 102, productId: 11, productPriceId: 21, type: ProductType::GENERAL, name: 'T-Shirt', quantity: 3, price: 15.0),
            ]))
            ->setAttendees(new Collection([
                $this->buildAttendee(id: 201, productId: 10, productPriceId: 20, firstName: 'Alice', email: 'alice@example.com'),
                $this->buildAttendee(id: 202, productId: 10, productPriceId: 20, firstName: 'Bob', email: 'bob@example.com'),
            ]));

        $this->withQuestions();
        $rows = $this->export->map($order);

        $this->assertCount(3, $rows);

        [$aliceRow, $bobRow, $shirtRow] = $rows;

        $this->assertSame(1, $aliceRow[self::COL_QUANTITY]);
        $this->assertSame('General Admission', $aliceRow[self::COL_PRODUCT_NAME]);
        $this->assertSame(201, $aliceRow[self::COL_ATTENDEE_ID]);
        $this->assertSame('Alice', $aliceRow[self::COL_ATTENDEE_FIRST_NAME]);
        $this->assertSame('alice@example.com', $aliceRow[self::COL_ATTENDEE_EMAIL]);

        $this->assertSame(1, $bobRow[self::COL_QUANTITY]);
        $this->assertSame(202, $bobRow[self::COL_ATTENDEE_ID]);

        $this->assertSame(3, $shirtRow[self::COL_QUANTITY]);
        $this->assertSame('T-Shirt', $shirtRow[self::COL_PRODUCT_NAME]);
        $this->assertSame(ProductType::GENERAL->name, $shirtRow[self::COL_PRODUCT_TYPE]);
        $this->assertSame('', $shirtRow[self::COL_ATTENDEE_ID]);
        $this->assertSame('', $shirtRow[self::COL_ATTENDEE_EMAIL]);

        foreach ($rows as $row) {
            $this->assertSame(1, $row[self::COL_ORDER_ID]);
            $this->assertSame(5, $row[self::COL_EVENT_ID]);
            $this->assertSame('SUMMER10', $row[self::COL_PROMO_CODE]);
        }
    }

    public function test_question_answers_are_mapped_to_the_correct_rows(): void
    {
        $orderQuestion = $this->buildQuestion(id: 501, title: 'How did you hear about us?', belongsTo: QuestionBelongsTo::ORDER);
        $productQuestion = $this->buildQuestion(id: 502, title: 'T-shirt size', belongsTo: QuestionBelongsTo::PRODUCT);

        $order = $this->buildOrder()
            ->setOrderItems(new Collection([
                $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'GA', quantity: 2, price: 25.0),
            ]))
            ->setAttendees(new Collection([
                $this->buildAttendee(id: 201, productId: 10, productPriceId: 20, firstName: 'Alice', email: 'alice@example.com'),
                $this->buildAttendee(id: 202, productId: 10, productPriceId: 20, firstName: 'Bob', email: 'bob@example.com'),
            ]))
            ->setQuestionAndAnswerViews(new Collection([
                (new QuestionAndAnswerViewDomainObject())->setQuestionId(501)->setAttendeeId(null)->setAnswer('A friend'),
                (new QuestionAndAnswerViewDomainObject())->setQuestionId(502)->setAttendeeId(201)->setAnswer('Large'),
            ]));

        $this->withQuestions(
            orderQuestions: new Collection([$orderQuestion]),
            productQuestions: new Collection([$productQuestion]),
        );

        $rows = $this->export->map($order);
        $headings = $this->export->headings();

        $this->assertCount(2, $rows);
        $orderAnswerIndex = array_search('How did you hear about us?', $headings, true);
        $productAnswerIndex = array_search('T-shirt size', $headings, true);

        [$aliceRow, $bobRow] = $rows;
        $this->assertSame('A friend', $aliceRow[$orderAnswerIndex]);
        $this->assertSame('A friend', $bobRow[$orderAnswerIndex]);
        $this->assertSame('Large', $aliceRow[$productAnswerIndex]);
        $this->assertSame('', $bobRow[$productAnswerIndex]);
    }

    public function test_ticket_item_with_no_attendees_emits_a_single_row_with_item_quantity(): void
    {
        $order = $this->buildOrder()
            ->setOrderItems(new Collection([
                $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'GA', quantity: 2, price: 25.0),
            ]))
            ->setAttendees(new Collection());

        $this->withQuestions();
        $rows = $this->export->map($order);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0][self::COL_QUANTITY]);
        $this->assertSame('', $rows[0][self::COL_ATTENDEE_ID]);
    }

    public function test_order_with_no_items_emits_a_fallback_row(): void
    {
        $order = $this->buildOrder();

        $this->withQuestions();
        $rows = $this->export->map($order);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0][self::COL_ORDER_ID]);
        $this->assertSame('', $rows[0][self::COL_ORDER_ITEM_ID]);
        $this->assertSame('', $rows[0][self::COL_ATTENDEE_ID]);
    }

    public function test_items_sharing_product_and_price_do_not_duplicate_attendees(): void
    {
        $order = $this->buildOrder()
            ->setOrderItems(new Collection([
                $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'GA', quantity: 1, price: 25.0),
                $this->buildItem(id: 102, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'GA', quantity: 1, price: 25.0),
            ]))
            ->setAttendees(new Collection([
                $this->buildAttendee(id: 201, productId: 10, productPriceId: 20, firstName: 'Alice', email: 'alice@example.com'),
            ]));

        $this->withQuestions();
        $rows = $this->export->map($order);

        $attendeeIds = array_column($rows, self::COL_ATTENDEE_ID);
        $this->assertSame(1, count(array_filter($attendeeIds, static fn($id) => $id === 201)));
    }

    public function test_every_row_matches_heading_width(): void
    {
        $order = $this->buildOrder()
            ->setOrderItems(new Collection([
                $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::TICKET, name: 'GA', quantity: 1, price: 25.0),
                $this->buildItem(id: 102, productId: 11, productPriceId: 21, type: ProductType::GENERAL, name: 'T-Shirt', quantity: 2, price: 15.0),
            ]))
            ->setAttendees(new Collection([
                $this->buildAttendee(id: 201, productId: 10, productPriceId: 20, firstName: 'Alice', email: 'alice@example.com'),
            ]));

        $this->withQuestions(
            orderQuestions: new Collection([
                $this->buildQuestion(id: 501, title: 'Order question', belongsTo: QuestionBelongsTo::ORDER),
            ]),
            productQuestions: new Collection([
                $this->buildQuestion(id: 502, title: 'Product question', belongsTo: QuestionBelongsTo::PRODUCT),
            ]),
        );

        $headingCount = count($this->export->headings());
        foreach ($this->export->map($order) as $row) {
            $this->assertCount($headingCount, $row);
        }
    }

    public function test_unit_price_before_discount_falls_back_to_price(): void
    {
        $item = $this->buildItem(id: 101, productId: 10, productPriceId: 20, type: ProductType::GENERAL, name: 'T-Shirt', quantity: 1, price: 15.0);
        $order = $this->buildOrder()->setOrderItems(new Collection([$item]));

        $this->withQuestions();
        $rows = $this->export->map($order);

        $this->assertSame(15.0, $rows[0][self::COL_UNIT_PRICE]);
        $this->assertSame(15.0, $rows[0][self::COL_UNIT_PRICE_BEFORE_DISCOUNT]);
    }

    private function withQuestions(?Collection $orderQuestions = null, ?Collection $productQuestions = null): void
    {
        $this->export->withData(
            new Collection(),
            $orderQuestions ?? new Collection(),
            $productQuestions ?? new Collection(),
        );
    }

    private function buildOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(1)
            ->setEventId(5)
            ->setShortId('o-short')
            ->setPublicId('O-ABC123')
            ->setFirstName('Buyer')
            ->setLastName('One')
            ->setEmail('buyer@example.com')
            ->setStatus('COMPLETED')
            ->setPaymentStatus('PAYMENT_RECEIVED')
            ->setCurrency('USD')
            ->setTotalBeforeAdditions(50.0)
            ->setTotalGross(57.0)
            ->setTotalTax(5.0)
            ->setTotalFee(2.0)
            ->setTotalRefunded(0.0)
            ->setPromoCode('SUMMER10')
            ->setIsManuallyCreated(false)
            ->setCreatedAt('2026-01-01 10:00:00');
    }

    private function buildItem(
        int         $id,
        int         $productId,
        int         $productPriceId,
        ProductType $type,
        string      $name,
        int         $quantity,
        float       $price,
    ): OrderItemDomainObject
    {
        return (new OrderItemDomainObject())
            ->setId($id)
            ->setOrderId(1)
            ->setProductId($productId)
            ->setProductPriceId($productPriceId)
            ->setProductType($type->name)
            ->setItemName($name)
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setTotalBeforeAdditions($price * $quantity)
            ->setTotalTax(0.0)
            ->setTotalServiceFee(0.0)
            ->setTotalGross($price * $quantity);
    }

    private function buildAttendee(
        int    $id,
        int    $productId,
        int    $productPriceId,
        string $firstName,
        string $email,
    ): AttendeeDomainObject
    {
        return (new AttendeeDomainObject())
            ->setId($id)
            ->setOrderId(1)
            ->setEventId(5)
            ->setProductId($productId)
            ->setProductPriceId($productPriceId)
            ->setFirstName($firstName)
            ->setLastName('Attendee')
            ->setEmail($email)
            ->setStatus('ACTIVE')
            ->setPublicId('A-' . $id)
            ->setShortId('a-short-' . $id);
    }

    private function buildQuestion(int $id, string $title, QuestionBelongsTo $belongsTo): QuestionDomainObject
    {
        return (new QuestionDomainObject())
            ->setId($id)
            ->setTitle($title)
            ->setType(QuestionTypeEnum::SINGLE_LINE_TEXT->name)
            ->setBelongsTo($belongsTo->name);
    }
}
