<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GenerateOrderTicketsPDFService
{
    public function __construct(
        private readonly QrCodeService $qrCodeService,
    )
    {
    }

    /**
     * Generate a multi-ticket PDF for every active attendee on the order.
     *
     * Caller MUST eager-load:
     *   - $order->getAttendees() with each attendee's Product
     *   - $event with Organizer + EventSettings
     *
     * The event is passed explicitly because callers typically load it
     * separately from the order (e.g. ResendOrderConfirmationAction does
     * not nest the event relation under the order).
     *
     * @return string Raw PDF bytes (begins with `%PDF-`).
     * @throws ResourceNotFoundException When the order has no ACTIVE attendees to generate tickets for.
     */
    public function generate(OrderDomainObject $order, EventDomainObject $event): string
    {
        $attendees = $order->getAttendees() ?? new Collection();

        $activeAttendees = $attendees->filter(
            fn(AttendeeDomainObject $attendee) => $attendee->getStatus() === AttendeeStatus::ACTIVE->name,
        )->values();

        if ($activeAttendees->isEmpty()) {
            throw new ResourceNotFoundException(__('No active attendees to generate tickets for'));
        }

        $qrCodes = [];
        foreach ($activeAttendees as $attendee) {
            $qrCodes[$attendee->getId()] = base64_encode(
                $this->qrCodeService->generatePng($attendee->getPublicId()),
            );
        }

        return Pdf::loadView('tickets', [
            'attendees' => $activeAttendees,
            'event' => $event,
            'organizer' => $event->getOrganizer(),
            'eventSettings' => $event->getEventSettings(),
            'qrCodes' => $qrCodes,
        ])->output();
    }
}
