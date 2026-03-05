<?php

namespace HiEvents\Services\Application\Handlers\Webhook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\Repository\Interfaces\WebhookRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeResource;
use HiEvents\Resources\CheckInList\AttendeeCheckInResource;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Resources\Product\ProductResource;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\Webhook\WebhookResponseHandlerService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class SendTestWebhookHandler
{
    public function __construct(
        private readonly WebhookRepositoryInterface    $webhookRepository,
        private readonly WebhookResponseHandlerService $webhookResponseHandlerService,
        private readonly LoggerInterface               $logger,
        private readonly Client                        $httpClient,
    )
    {
    }

    /**
     * Fire a test payload at the webhook URL synchronously and return the result.
     *
     * @return array{response_code: int, response_body: string|null, success: bool}
     */
    public function handle(int $eventId, int $webhookId, ?string $eventType = null): array
    {
        $webhook = $this->webhookRepository->findFirstWhere([
            'id'       => $webhookId,
            'event_id' => $eventId,
        ]);

        if (!$webhook) {
            return [
                'response_code' => 0,
                'response_body' => 'Webhook not found.',
                'success'       => false,
            ];
        }

        $eventTypes = $webhook->getEventTypes();
        $resolvedType = $eventType ?? (is_array($eventTypes) ? ($eventTypes[0] ?? DomainEventType::ORDER_CREATED->value) : $eventTypes);

        $payload = [
            'event_type'    => $resolvedType,
            'event_sent_at' => now()->toIso8601String(),
            'is_test'       => true,
            'payload'       => $this->buildStubPayload($resolvedType, $eventId),
        ];

        $payloadJson = json_encode($payload);
        $signature   = hash_hmac('sha256', $payloadJson, $webhook->getSecret());

        /** @var Response|null $guzzleResponse */
        $guzzleResponse = null;
        $responseBody   = null;

        try {
            $guzzleResponse = $this->httpClient->post($webhook->getUrl(), [
                'headers'         => [
                    'Content-Type' => 'application/json',
                    'Signature'    => $signature,
                ],
                'body'            => $payloadJson,
                'timeout'         => 10,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]);
        } catch (RequestException | ConnectException $e) {
            $this->logger->warning('Webhook test request failed', [
                'webhook_id' => $webhookId,
                'error'      => $e->getMessage(),
            ]);
            $responseBody = $e->getMessage();
        }

        $responseCode = $guzzleResponse?->getStatusCode() ?? 0;
        if ($guzzleResponse !== null) {
            $responseBody = substr((string) $guzzleResponse->getBody(), 0, 1000);
        }

        $this->webhookResponseHandlerService->handleResponse(
            eventId:   $eventId,
            webhookId: $webhookId,
            eventType: $resolvedType,
            payload:   $payload,
            response:  $guzzleResponse,
        );

        return [
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'success'       => $responseCode >= 200 && $responseCode < 300,
        ];
    }

    /**
     * Build a stub payload by constructing real DomainObjects and rendering them through
     * the actual Resource classes. This ensures the shape always mirrors the live code —
     * if a field is added to a DomainObject or Resource, the test payload picks it up
     * automatically without any changes needed here.
     */
    private function buildStubPayload(string $eventType, int $eventId): array
    {
        $fakeRequest = Request::create('/');

        return match (true) {
            in_array($eventType, ['attendee.created', 'attendee.updated', 'attendee.cancelled'], true)
                => (new AttendeeResource($this->makeStubAttendee($eventId)))->toArray($fakeRequest),

            in_array($eventType, ['order.created', 'order.updated', 'order.marked_as_paid', 'order.refunded', 'order.cancelled'], true)
                => (new OrderResource($this->makeStubOrder($eventId)))->toArray($fakeRequest),

            in_array($eventType, ['product.created', 'product.updated', 'product.deleted'], true)
                => (new ProductResource($this->makeStubProduct($eventId)))->toArray($fakeRequest),

            in_array($eventType, ['checkin.created', 'checkin.deleted'], true)
                => (new AttendeeCheckInResource($this->makeStubCheckIn($eventId)))->toArray($fakeRequest),

            default => [],
        };
    }

    private function makeStubAttendee(int $eventId, bool $withProduct = false): AttendeeDomainObject
    {
        $now = now()->toIso8601String();

        $attendee = (new AttendeeDomainObject())
            ->setId(0)
            ->setOrderId(0)
            ->setProductId(0)
            ->setProductPriceId(0)
            ->setEventId($eventId)
            ->setEmail('jane.smith@example.com')
            ->setStatus('ACTIVE')
            ->setFirstName('Jane')
            ->setLastName('Smith')
            ->setPublicId('00000000-0000-0000-0000-000000000000')
            ->setShortId('TESTID')
            ->setLocale('en')
            ->setNotes(null)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
            ->setQuestionAndAnswerViews(new Collection([$this->makeStubQuestionAnswer($eventId, 'PRODUCT')]))
            ->setCheckIns(new Collection());

        // The real attendee.* dispatch does not eager-load product; the real
        // order.* dispatch embeds attendees without product relation either.
        // Pass $withProduct = true only if you specifically need it.
        if ($withProduct) {
            $attendee->setProduct($this->makeStubProduct($eventId));
        }

        return $attendee;
    }

    private function makeStubOrder(int $eventId): OrderDomainObject
    {
        $now = now()->toIso8601String();

        $orderItem = (new OrderItemDomainObject())
            ->setId(0)
            ->setOrderId(0)
            ->setProductId(0)
            ->setProductPriceId(0)
            ->setQuantity(1)
            ->setItemName('General Admission')
            ->setPrice(25.00)
            ->setTotalBeforeAdditions(25.00)
            ->setTotalGross(27.50)
            ->setTotalTax(0.00)
            ->setTotalServiceFee(2.50)
            ->setProductType('TICKET');

        return (new OrderDomainObject())
            ->setId(0)
            ->setEventId($eventId)
            ->setShortId('TEST00')
            ->setPublicId('00000000-0000-0000-0000-000000000000')
            ->setTotalBeforeAdditions(25.00)
            ->setTotalGross(27.50)
            ->setTotalTax(0.00)
            ->setTotalFee(2.50)
            ->setTotalRefunded(0.00)
            ->setStatus('COMPLETED')
            ->setPaymentStatus('PAID')
            ->setRefundStatus(null)
            ->setCurrency('USD')
            ->setFirstName('Jane')
            ->setLastName('Smith')
            ->setEmail('jane.smith@example.com')
            ->setIsManuallyCreated(false)
            ->setTaxesAndFeesRollup(null)
            ->setAddress(null)
            ->setNotes(null)
            ->setPaymentProvider('STRIPE')
            ->setPromoCode(null)
            ->setAffiliateCode(null)
            ->setCreatedAt($now)
            ->setOrderItems(new Collection([$orderItem]))
            // Real dispatch loads attendees with nested question_and_answer_views
            ->setAttendees(new Collection([$this->makeStubAttendee($eventId)]))
            ->setQuestionAndAnswerViews(new Collection([$this->makeStubQuestionAnswer($eventId, 'ORDER')]));
    }

    private function makeStubProduct(int $eventId): ProductDomainObject
    {
        $now = now()->toIso8601String();

        // Note: the real product.* dispatch also loads ProductPrices and TaxAndFees
        // relations via the repository. Those are populated at query time and cannot
        // be constructed without DB data, so prices/taxes will be absent here.
        // The resource renders them conditionally so they simply won't appear.
        return (new ProductDomainObject())
            ->setId(0)
            ->setEventId($eventId)
            ->setTitle('General Admission')
            ->setType('PAID')
            ->setProductType('TICKET')
            ->setOrder(1)
            ->setDescription(null)
            ->setMaxPerOrder(10)
            ->setMinPerOrder(1)
            ->setSaleStartDate(null)
            ->setSaleEndDate(null)
            ->setHideBeforeSaleStartDate(false)
            ->setHideAfterSaleEndDate(false)
            ->setStartCollapsed(false)
            ->setShowQuantityRemaining(false)
            ->setHideWhenSoldOut(false)
            ->setIsHiddenWithoutPromoCode(false)
            ->setIsHidden(false)
            ->setIsHighlighted(false)
            ->setHighlightMessage(null)
            ->setProductCategoryId(null)
            ->setTicketsPerGroup(null)
            ->setCreatedAt($now);
    }

    private function makeStubCheckIn(int $eventId): AttendeeCheckInDomainObject
    {
        $now = now()->toIso8601String();

        $checkIn = (new AttendeeCheckInDomainObject())
            ->setId(0)
            ->setAttendeeId(0)
            ->setCheckInListId(0)
            ->setProductId(0)
            ->setEventId($eventId)
            ->setShortId('TESTCK')
            ->setIpAddress('0.0.0.0')
            ->setCreatedAt($now);

        // Real checkin.* dispatch loads the attendee relation.
        $checkIn->setAttendee($this->makeStubAttendee($eventId));

        return $checkIn;
    }

    /**
     * Build one representative question answer using the real domain object so the
     * field list stays in sync with QuestionAnswerViewResource automatically.
     *
     * @param string $belongsTo 'ORDER' or 'PRODUCT'
     */
    private function makeStubQuestionAnswer(int $eventId, string $belongsTo = 'ORDER'): QuestionAndAnswerViewDomainObject
    {
        $isProduct = $belongsTo === 'PRODUCT';

        return (new QuestionAndAnswerViewDomainObject())
            ->setQuestionAnswerId(0)
            ->setQuestionId(0)
            ->setEventId($eventId)
            ->setOrderId(0)
            ->setProductId($isProduct ? 0 : null)
            ->setProductTitle($isProduct ? 'General Admission' : null)
            ->setTitle('Dietary requirements')
            ->setQuestionType('SHORT_TEXT')
            ->setQuestionRequired(false)
            ->setQuestionDescription(null)
            ->setQuestionOptions(null)
            ->setBelongsTo($belongsTo)
            ->setAnswer('No allergies')
            ->setAttendeeId($isProduct ? 0 : null)
            ->setAttendeePublicId($isProduct ? '00000000-0000-0000-0000-000000000000' : null)
            ->setFirstName($isProduct ? 'Jane' : null)
            ->setLastName($isProduct ? 'Smith' : null);
    }
}
