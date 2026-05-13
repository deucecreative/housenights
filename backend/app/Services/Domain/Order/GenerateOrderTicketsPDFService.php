<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Illuminate\Support\Collection;
use RuntimeException;

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
     *   - $order->getEvent() with Organizer + EventSettings
     *
     * @return string Raw PDF bytes (begins with `%PDF-`).
     * @throws RuntimeException When the order has no ACTIVE attendees to generate tickets for.
     */
    public function generate(OrderDomainObject $order): string
    {
        $attendees = $order->getAttendees() ?? new Collection();

        $activeAttendees = $attendees->filter(
            fn(AttendeeDomainObject $attendee) => $attendee->getStatus() === AttendeeStatus::ACTIVE->name,
        )->values();

        if ($activeAttendees->isEmpty()) {
            throw new RuntimeException('No active attendees to generate tickets for');
        }

        $qrCodes = [];
        foreach ($activeAttendees as $attendee) {
            $qrCodes[$attendee->getId()] = base64_encode(
                $this->qrCodeService->generatePng($attendee->getPublicId()),
            );
        }

        $event = $order->getEvent();

        return Pdf::loadView('tickets', [
            'attendees' => $activeAttendees,
            'event' => $event,
            'organizer' => $event?->getOrganizer(),
            'eventSettings' => $event?->getEventSettings(),
            'qrCodes' => $qrCodes,
        ])->output();
    }
}
