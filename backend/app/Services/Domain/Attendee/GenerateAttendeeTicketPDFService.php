<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
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
     * @return string Raw PDF bytes (begins with `%PDF-`).
     */
    public function generate(AttendeeDomainObject $attendee, EventDomainObject $event): string
    {
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
            'qrCodes' => $qrCodes,
        ])->output();
    }
}
