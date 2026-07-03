<?php

namespace HiEvents\Exports;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    private LengthAwarePaginator|Collection $orders;
    private Collection $orderQuestions;
    private Collection $productQuestions;

    public function __construct(private QuestionAnswerFormatter $questionAnswerFormatter)
    {
    }

    public function withData(
        LengthAwarePaginator|Collection $orders,
        Collection                      $orderQuestions,
        Collection                      $productQuestions,
    ): OrdersExport
    {
        $this->orders = $orders;
        $this->orderQuestions = $orderQuestions;
        $this->productQuestions = $productQuestions;
        return $this;
    }

    public function collection(): Collection
    {
        return $this->orders instanceof LengthAwarePaginator
            ? collect($this->orders->items())
            : $this->orders;
    }

    public function headings(): array
    {
        $orderQuestionTitles = $this->orderQuestions->map(fn($question) => $question->getTitle())->toArray();
        $productQuestionTitles = $this->productQuestions->map(fn($question) => $question->getTitle())->toArray();

        return array_merge([
            __('Order ID'),
            __('First Name'),
            __('Last Name'),
            __('Email'),
            __('Total Before Additions'),
            __('Total Gross'),
            __('Total Tax'),
            __('Total Fee'),
            __('Total Refunded'),
            __('Status'),
            __('Payment Status'),
            __('Refund Status'),
            __('Currency'),
            __('Created At'),
            __('Public ID'),
            __('Payment Provider'),
            __('Is Partially Refunded'),
            __('Is Fully Refunded'),
            __('Is Free Order'),
            __('Is Manually Created'),
            __('Billing Address'),
            __('Notes'),
            __('Promo Code'),
            __('Opted In To Marketing'),
            __('Affiliate Name'),
            __('Affiliate Code'),
            __('Event ID'),
            __('Order Item ID'),
            __('Product ID'),
            __('Product Price ID'),
            __('Product Type'),
            __('Product Name'),
            __('Quantity'),
            __('Unit Price'),
            __('Unit Price Before Discount'),
            __('Item Total Before Additions'),
            __('Item Total Tax'),
            __('Item Total Service Fee'),
            __('Item Total Gross'),
            __('Item Taxes and Fees Rollup'),
            __('Attendee ID'),
            __('Attendee Public ID'),
            __('Attendee First Name'),
            __('Attendee Last Name'),
            __('Attendee Email'),
            __('Attendee Status'),
        ], $orderQuestionTitles, $productQuestionTitles);
    }

    /**
     * Emits one row per purchased unit: ticket items expand to one row per
     * attendee, other items (or ticket items with no attendees) emit a single
     * row carrying the item quantity.
     *
     * @param OrderDomainObject $order
     * @return array<int, array>
     */
    public function map($order): array
    {
        $orderColumns = $this->orderColumns($order);
        $orderAnswers = $this->orderAnswers($order);

        $attendeePool = ($order->getAttendees() ?? new Collection())
            ->groupBy(fn(AttendeeDomainObject $attendee) => $attendee->getProductId() . ':' . $attendee->getProductPriceId());

        $rows = [];
        foreach ($order->getOrderItems() ?? new Collection() as $item) {
            // pull() consumes the group, so a duplicate item key can't re-emit the same attendees
            $attendees = $item->getProductType() === ProductType::TICKET->name
                ? ($attendeePool->pull($item->getProductId() . ':' . $item->getProductPriceId()) ?? new Collection())
                : new Collection();

            if ($attendees->isEmpty()) {
                $rows[] = array_merge(
                    $orderColumns,
                    $this->itemColumns($item, $item->getQuantity()),
                    $this->emptyAttendeeColumns(),
                    $orderAnswers,
                    $this->emptyProductAnswers(),
                );
                continue;
            }

            foreach ($attendees as $attendee) {
                $rows[] = array_merge(
                    $orderColumns,
                    $this->itemColumns($item, 1),
                    $this->attendeeColumns($attendee),
                    $orderAnswers,
                    $this->productAnswersForAttendee($order, $attendee),
                );
            }
        }

        if ($rows === []) {
            $rows[] = array_merge(
                $orderColumns,
                $this->emptyItemColumns(),
                $this->emptyAttendeeColumns(),
                $orderAnswers,
                $this->emptyProductAnswers(),
            );
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function orderColumns(OrderDomainObject $order): array
    {
        return [
            $order->getId(),
            $order->getFirstName(),
            $order->getLastName(),
            $order->getEmail(),
            $order->getTotalBeforeAdditions(),
            $order->getTotalGross(),
            $order->getTotalTax(),
            $order->getTotalFee(),
            $order->getTotalRefunded(),
            $order->getStatus(),
            $order->getPaymentStatus(),
            $order->getRefundStatus(),
            $order->getCurrency(),
            Carbon::parse($order->getCreatedAt())->format('Y-m-d H:i:s'),
            $order->getPublicId(),
            $order->getPaymentProvider(),
            $order->isPartiallyRefunded(),
            $order->isFullyRefunded(),
            $order->isFreeOrder(),
            $order->getIsManuallyCreated(),
            $order->getBillingAddressString(),
            $order->getNotes(),
            $order->getPromoCode(),
            $order->getOptedIntoMarketingAt() ? 'Yes' : 'No',
            $order->getAffiliate()?->getName() ?? '',
            $order->getAffiliateCode() ?? $order->getAffiliate()?->getCode() ?? '',
            $order->getEventId(),
        ];
    }

    private function itemColumns(OrderItemDomainObject $item, int $quantity): array
    {
        return [
            $item->getId(),
            $item->getProductId(),
            $item->getProductPriceId(),
            $item->getProductType(),
            $item->getItemName() ?? __('Unknown'),
            $quantity,
            $item->getPrice(),
            $item->getPriceBeforeDiscount() ?? $item->getPrice(),
            $item->getTotalBeforeAdditions(),
            $item->getTotalTax(),
            $item->getTotalServiceFee(),
            $item->getTotalGross(),
            $this->formatRollup($item->getTaxesAndFeesRollup()),
        ];
    }

    private function emptyItemColumns(): array
    {
        return array_fill(0, 13, '');
    }

    private function attendeeColumns(AttendeeDomainObject $attendee): array
    {
        return [
            $attendee->getId(),
            $attendee->getPublicId(),
            $attendee->getFirstName(),
            $attendee->getLastName(),
            $attendee->getEmail(),
            $attendee->getStatus(),
        ];
    }

    private function emptyAttendeeColumns(): array
    {
        return array_fill(0, 6, '');
    }

    private function orderAnswers(OrderDomainObject $order): array
    {
        return $this->orderQuestions->map(function (QuestionDomainObject $question) use ($order) {
            $answer = $order->getQuestionAndAnswerViews()
                ?->first(fn($qav) => $qav->getQuestionId() === $question->getId())?->getAnswer() ?? '';

            return $this->questionAnswerFormatter->getAnswerAsText(
                $answer,
                QuestionTypeEnum::fromName($question->getType()),
            );
        })->toArray();
    }

    private function productAnswersForAttendee(OrderDomainObject $order, AttendeeDomainObject $attendee): array
    {
        return $this->productQuestions->map(function (QuestionDomainObject $question) use ($order, $attendee) {
            $answer = $order->getQuestionAndAnswerViews()
                ?->first(fn($qav) => $qav->getQuestionId() === $question->getId()
                    && $qav->getAttendeeId() === $attendee->getId())?->getAnswer() ?? '';

            return $this->questionAnswerFormatter->getAnswerAsText(
                $answer,
                QuestionTypeEnum::fromName($question->getType()),
            );
        })->toArray();
    }

    private function emptyProductAnswers(): array
    {
        return array_fill(0, $this->productQuestions->count(), '');
    }

    private function formatRollup(array|string|null $rollup): string
    {
        if ($rollup === null) {
            return '';
        }

        return is_string($rollup) ? $rollup : json_encode($rollup);
    }
}
