<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Order\OrderTicketsMail;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SendEventReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $eventId)
    {
    }

    public function handle(
        EventRepositoryInterface    $eventRepository,
        OrderRepositoryInterface    $orderRepository,
        AttendeeRepositoryInterface $attendeeRepository,
        Mailer                      $mailer,
        LoggerInterface             $logger,
    ): void
    {
        $event = $eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->findById($this->eventId);

        if ($event === null) {
            $logger->warning('SendEventReminderJob: event not found', ['event_id' => $this->eventId]);
            return;
        }

        $eventSettings = $event->getEventSettings();
        $organizer = $event->getOrganizer();

        if ($eventSettings === null || $organizer === null) {
            $logger->warning('SendEventReminderJob: missing event settings or organizer', [
                'event_id' => $this->eventId,
            ]);
            return;
        }

        // Sanity check: still in the future, still enabled.
        if (!$eventSettings->getPreEventReminderEnabled()) {
            return;
        }

        $startDate = $event->getStartDate() ? Carbon::parse($event->getStartDate()) : null;
        if ($startDate === null || $startDate->isPast()) {
            return;
        }

        $orders = $orderRepository
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [new Relationship(ProductDomainObject::class, name: 'product')],
            ))
            ->findWhere([
                OrderDomainObjectAbstract::EVENT_ID => $this->eventId,
                OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            ]);

        $now = Carbon::now()->toDateTimeString();

        foreach ($orders as $order) {
            /** @var OrderDomainObject $order */

            // Send the consolidated purchaser email when no reminder has been sent yet.
            if ($order->getReminderSentAt() === null) {
                $activeAttendees = $this->getActiveAttendees($order);

                if ($activeAttendees->isNotEmpty()) {
                    try {
                        $mailer
                            ->to($order->getEmail())
                            ->locale($order->getLocale())
                            ->queue(new OrderTicketsMail(
                                order: $order,
                                event: $event,
                                eventSettings: $eventSettings,
                                organizer: $organizer,
                                isReminder: true,
                            ));

                        $orderRepository->updateWhere(
                            attributes: [OrderDomainObjectAbstract::REMINDER_SENT_AT => $now],
                            where: [['id', '=', $order->getId()]],
                        );
                    } catch (Throwable $exception) {
                        $logger->error('SendEventReminderJob: failed to queue purchaser reminder', [
                            'event_id' => $this->eventId,
                            'order_id' => $order->getId(),
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            // Now send AttendeeTicketMail to each unique non-purchaser attendee email
            // that has not yet received a reminder. Set reminder_sent_at for every
            // attendee row sharing that email, not just the one we sent to.
            $attendeesByEmail = $this->groupUnsentAttendeesByEmail($order);

            foreach ($attendeesByEmail as $email => $attendees) {
                /** @var AttendeeDomainObject $attendee */
                $attendee = $attendees[0];

                try {
                    $mailer
                        ->to($email)
                        ->locale($attendee->getLocale() ?: $order->getLocale())
                        ->queue(new AttendeeTicketMail(
                            order: $order,
                            attendee: $attendee,
                            event: $event,
                            eventSettings: $eventSettings,
                            organizer: $organizer,
                            renderedTemplate: null,
                            isReminder: true,
                        ));

                    $ids = array_map(static fn (AttendeeDomainObject $a) => $a->getId(), $attendees);
                    $attendeeRepository->updateWhere(
                        attributes: [AttendeeDomainObjectAbstract::REMINDER_SENT_AT => $now],
                        where: [['id', 'in', $ids]],
                    );
                } catch (Throwable $exception) {
                    $logger->error('SendEventReminderJob: failed to queue attendee reminder', [
                        'event_id' => $this->eventId,
                        'order_id' => $order->getId(),
                        'attendee_email' => $email,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, AttendeeDomainObject>
     */
    private function getActiveAttendees(OrderDomainObject $order): \Illuminate\Support\Collection
    {
        $attendees = $order->getAttendees() ?? new \Illuminate\Support\Collection();

        return $attendees->filter(
            static fn (AttendeeDomainObject $attendee) => $attendee->getStatus() === AttendeeStatus::ACTIVE->name,
        )->values();
    }

    /**
     * Group ACTIVE non-purchaser attendees that still need a reminder, keyed by email.
     *
     * @return array<string, AttendeeDomainObject[]>
     */
    private function groupUnsentAttendeesByEmail(OrderDomainObject $order): array
    {
        $grouped = [];
        $purchaserEmail = strtolower((string) $order->getEmail());

        foreach ($order->getAttendees() ?? [] as $attendee) {
            /** @var AttendeeDomainObject $attendee */
            if ($attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
                continue;
            }

            if ($attendee->getReminderSentAt() !== null) {
                continue;
            }

            $email = $attendee->getEmail();
            if (!$email) {
                continue;
            }

            if (strtolower($email) === $purchaserEmail) {
                continue;
            }

            $grouped[$email][] = $attendee;
        }

        return $grouped;
    }
}
