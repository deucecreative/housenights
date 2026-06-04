<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Order\OrderTicketsMail;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Mail\Mailer;

class SendOrderDetailsService
{
    public function __construct(
        private readonly EventRepositoryInterface  $eventRepository,
        private readonly OrderRepositoryInterface  $orderRepository,
        private readonly Mailer                    $mailer,
        private readonly SendAttendeeTicketService $sendAttendeeTicketService,
        private readonly MailBuilderService        $mailBuilderService,
    )
    {
    }

    public function sendOrderSummaryAndTicketEmails(OrderDomainObject $order): void
    {
        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [new Relationship(ProductDomainObject::class, name: 'product')],
            ))
            ->loadRelation(InvoiceDomainObject::class)
            ->loadRelation(new Relationship(AffiliateDomainObject::class, name: 'affiliate'))
            ->findById($order->getId());

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($order->getEventId());

        if ($order->isOrderCompleted() || $order->isOrderAwaitingOfflinePayment()) {
            $this->sendOrderSummaryEmails($order, $event);
            $this->sendAttendeeTicketEmails($order, $event);
        }

        if ($order->isOrderFailed()) {
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderFailed(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                ));
        }
    }

    public function sendCustomerOrderSummary(
        OrderDomainObject        $order,
        EventDomainObject        $event,
        OrganizerDomainObject    $organizer,
        EventSettingDomainObject $eventSettings,
        ?InvoiceDomainObject     $invoice = null
    ): void
    {
        $mail = $this->mailBuilderService->buildOrderSummaryMail(
            $order,
            $event,
            $eventSettings,
            $organizer,
            $invoice
        );

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send($mail);
    }

    /**
     * Send the individual ticket email to each attendee.
     *
     * The purchaser is seeded into the dedupe set up front so that an attendee
     * sharing the purchaser's email is skipped — they already receive the
     * consolidated OrderTicketsMail. This mirrors SendEventReminderJob, which
     * excludes the purchaser from the per-attendee emails. Comparison is
     * case-insensitive, also matching the reminder job.
     *
     * Only ACTIVE attendees are emailed, matching SendEventReminderJob and
     * OrderTicketsMail — a cancelled attendee must not receive a ticket.
     */
    private function sendAttendeeTicketEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        $sentEmails = [strtolower((string) $order->getEmail())];
        foreach ($order->getAttendees() as $attendee) {
            if ($attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
                continue;
            }

            $email = strtolower((string) $attendee->getEmail());
            if (in_array($email, $sentEmails, true)) {
                continue;
            }

            $this->sendAttendeeTicketService->send(
                order: $order,
                attendee: $attendee,
                event: $event,
                eventSettings: $event->getEventSettings(),
                organizer: $event->getOrganizer(),
            );

            $sentEmails[] = $email;
        }
    }

    private function sendOrderSummaryEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        $this->sendCustomerOrderSummary(
            order: $order,
            event: $event,
            organizer: $event->getOrganizer(),
            eventSettings: $event->getEventSettings(),
            invoice: $order->getLatestInvoice(),
        );

        // The initial-order flow also fans out the per-attendee emails (below),
        // so the consolidated email may truthfully say the others were emailed.
        $this->sendOrderTicketsToPurchaser($order, $event, attendeesAlsoEmailed: true);

        if ($order->getIsManuallyCreated() || !$event->getEventSettings()->getNotifyOrganizerOfNewOrders()) {
            return;
        }

        $this->mailer
            ->to($event->getOrganizer()->getEmail())
            ->send(new OrderSummaryForOrganizer($order, $event, $order->getAffiliate()?->getName()));
    }

    /**
     * Send the purchaser a consolidated tickets email (inline QR per attendee + multi-ticket PDF).
     *
     * Skips when the order has no ACTIVE attendees, since the PDF service requires at least one.
     *
     * $attendeesAlsoEmailed must only be true when the caller is also dispatching
     * the per-attendee emails in the same flow — the resend-to-purchaser paths
     * leave it false so the email doesn't falsely claim the others were emailed.
     */
    public function sendOrderTicketsToPurchaser(
        OrderDomainObject $order,
        EventDomainObject $event,
        bool              $isReminder = false,
        bool              $attendeesAlsoEmailed = false,
    ): void
    {
        if (!$this->orderHasActiveAttendees($order)) {
            return;
        }

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send(new OrderTicketsMail(
                order: $order,
                event: $event,
                eventSettings: $event->getEventSettings(),
                organizer: $event->getOrganizer(),
                isReminder: $isReminder,
                attendeesAlsoEmailed: $attendeesAlsoEmailed,
            ));
    }

    private function orderHasActiveAttendees(OrderDomainObject $order): bool
    {
        $attendees = $order->getAttendees();

        if ($attendees === null) {
            return false;
        }

        foreach ($attendees as $attendee) {
            if ($attendee->getStatus() === AttendeeStatus::ACTIVE->name) {
                return true;
            }
        }

        return false;
    }
}
