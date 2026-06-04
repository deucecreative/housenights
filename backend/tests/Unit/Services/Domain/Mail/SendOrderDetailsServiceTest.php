<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SendOrderDetailsServiceTest extends TestCase
{
    private MockInterface|EventRepositoryInterface $eventRepository;
    private MockInterface|OrderRepositoryInterface $orderRepository;
    private MockInterface|Mailer $mailer;
    private MockInterface|SendAttendeeTicketService $sendAttendeeTicketService;
    private MockInterface|MailBuilderService $mailBuilderService;
    private SendOrderDetailsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->mailer = Mockery::mock(Mailer::class);
        $this->sendAttendeeTicketService = Mockery::mock(SendAttendeeTicketService::class);
        $this->mailBuilderService = Mockery::mock(MailBuilderService::class);

        $this->service = new SendOrderDetailsService(
            $this->eventRepository,
            $this->orderRepository,
            $this->mailer,
            $this->sendAttendeeTicketService,
            $this->mailBuilderService,
        );
    }

    public function test_does_not_send_attendee_email_to_the_purchaser(): void
    {
        // Solo order: the only attendee shares the purchaser's email (in a
        // different case, to also prove the comparison is case-insensitive).
        $order = $this->buildOrder('buyer@example.com', [
            ['BUYER@example.com', AttendeeStatus::ACTIVE->name],
        ]);

        $this->primeMailFlow($order);

        // The purchaser already gets the consolidated email, so no per-attendee send.
        $this->sendAttendeeTicketService->shouldReceive('send')->never();

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    public function test_sends_attendee_email_only_to_non_purchaser_attendees(): void
    {
        $order = $this->buildOrder('buyer@example.com', [
            ['buyer@example.com', AttendeeStatus::ACTIVE->name],
            ['guest@example.com', AttendeeStatus::ACTIVE->name],
        ]);

        $this->primeMailFlow($order);

        $sentTo = [];
        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($ord, AttendeeDomainObject $attendee) use ($order, &$sentTo) {
                $sentTo[] = $attendee->getEmail();
                return $ord === $order;
            });

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertSame(['guest@example.com'], $sentTo);
    }

    public function test_does_not_send_attendee_email_to_cancelled_attendees(): void
    {
        $order = $this->buildOrder('buyer@example.com', [
            ['buyer@example.com', AttendeeStatus::ACTIVE->name],
            ['guest@example.com', AttendeeStatus::CANCELLED->name],
        ]);

        $this->primeMailFlow($order);

        // The purchaser is skipped (consolidated email) and the other attendee is
        // cancelled, so no per-attendee ticket should go out.
        $this->sendAttendeeTicketService->shouldReceive('send')->never();

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    public function test_sends_attendee_email_to_awaiting_payment_attendees_on_offline_orders(): void
    {
        // Offline-payment order: the order is awaiting payment and attendees are
        // AWAITING_PAYMENT, not yet ACTIVE. The consolidated OrderTicketsMail is
        // gated out (no ACTIVE attendees), so EVERY attendee — including the
        // purchaser-attendee — must get their own AttendeeTicketMail (with the
        // pending-payment banner). The purchaser is only deduped when the
        // consolidated email actually goes out.
        $order = $this->buildOrder('buyer@example.com', [
            ['buyer@example.com', AttendeeStatus::AWAITING_PAYMENT->name],
            ['guest@example.com', AttendeeStatus::AWAITING_PAYMENT->name],
        ], OrderStatus::AWAITING_OFFLINE_PAYMENT->name);

        $this->primeMailFlow($order);

        $sentTo = [];
        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->twice()
            ->withArgs(function ($ord, AttendeeDomainObject $attendee) use ($order, &$sentTo) {
                $sentTo[] = $attendee->getEmail();
                return $ord === $order;
            });

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertSame(['buyer@example.com', 'guest@example.com'], $sentTo);
    }

    public function test_sole_purchaser_attendee_still_gets_ticket_on_offline_orders(): void
    {
        // The common case: a single-attendee offline-payment order where the
        // buyer is the attendee. The consolidated email can't carry their ticket
        // (no ACTIVE attendees), so the per-attendee email must still be sent —
        // otherwise they receive no ticket at all.
        $order = $this->buildOrder('buyer@example.com', [
            ['buyer@example.com', AttendeeStatus::AWAITING_PAYMENT->name],
        ], OrderStatus::AWAITING_OFFLINE_PAYMENT->name);

        $this->primeMailFlow($order);

        $sentTo = [];
        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($ord, AttendeeDomainObject $attendee) use (&$sentTo) {
                $sentTo[] = $attendee->getEmail();
                return true;
            });

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertSame(['buyer@example.com'], $sentTo);
    }

    /**
     * Stub out the repository reloads and the purchaser-facing summary/tickets
     * mail so the test can focus on which attendee emails are dispatched.
     */
    private function primeMailFlow(OrderDomainObject $order): void
    {
        $organizer = (new OrganizerDomainObject())
            ->setId(11)
            ->setName('Organizer')
            ->setEmail('organizer@example.com');

        $eventSettings = (new EventSettingDomainObject())
            ->setId(22)
            ->setEventId(33)
            ->setSupportEmail('support@example.com')
            ->setNotifyOrganizerOfNewOrders(false); // skip the organizer notification path

        $event = (new EventDomainObject())
            ->setId(33)
            ->setTitle('Test Event')
            ->setStartDate('2030-01-01 19:00:00')
            ->setTimezone('UTC');
        $event->setOrganizer($organizer);
        $event->setEventSettings($eventSettings);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findById')->with($order->getId())->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with($order->getEventId())->andReturn($event);

        $this->mailBuilderService
            ->shouldReceive('buildOrderSummaryMail')
            ->andReturn(Mockery::mock(OrderSummary::class));

        // mailer->to(...)->locale(...)->send(...) for the order summary + order tickets emails.
        $pendingMail = Mockery::mock();
        $pendingMail->shouldReceive('locale')->andReturnSelf();
        $pendingMail->shouldReceive('send');
        $this->mailer->shouldReceive('to')->andReturn($pendingMail);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $attendees [email, status]
     */
    private function buildOrder(string $purchaserEmail, array $attendees, ?string $orderStatus = null): OrderDomainObject
    {
        $attendeeObjects = new Collection();
        $id = 500;
        foreach ($attendees as [$email, $status]) {
            $attendeeObjects->push(
                (new AttendeeDomainObject())
                    ->setId(++$id)
                    ->setOrderId(701)
                    ->setEventId(33)
                    ->setEmail($email)
                    ->setStatus($status),
            );
        }

        $order = (new OrderDomainObject())
            ->setId(701)
            ->setEventId(33)
            ->setEmail($purchaserEmail)
            ->setStatus($orderStatus ?? OrderStatus::COMPLETED->name);
        $order->setAttendees($attendeeObjects);

        return $order;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
