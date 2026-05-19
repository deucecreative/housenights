<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Illuminate\Support\Collection;

class GenerateAttendeeTicketPDFService
{
    public function __construct(
        private readonly QrCodeService $qrCodeService,
    )
    {
    }

    /**
     * Generate a single-ticket PDF for the given attendee.
     *
     * Caller MUST eager-load:
     *   - $attendee->getProduct()
     *   - $event->getOrganizer()
     *   - $event->getEventSettings()
     *
     * $order is optional — when supplied, the template surfaces the order
     * reference. Pass it from contexts where it's already loaded (e.g.
     * download endpoints) and omit it elsewhere to keep the existing
     * email-attachment callsite signature stable.
     *
     * @return string Raw PDF bytes (begins with `%PDF-`).
     */
    public function generate(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        ?OrderDomainObject $order = null,
    ): string {
        $qrCodes = [
            $attendee->getId() => base64_encode(
                $this->qrCodeService->generatePng($attendee->getPublicId()),
            ),
        ];

        return Pdf::loadView('tickets', [
            'attendees' => new Collection([$attendee]),
            'event' => $event,
            'organizer' => $event->getOrganizer(),
            'eventSettings' => $event->getEventSettings(),
            'order' => $order,
            'qrCodes' => $qrCodes,
        ])->output();
    }
}
