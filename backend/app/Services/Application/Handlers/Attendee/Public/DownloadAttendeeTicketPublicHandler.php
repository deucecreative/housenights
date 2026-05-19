<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\Public;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use HiEvents\Services\Domain\Pdf\DTO\PdfDownloadResponseDTO;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadAttendeeTicketPublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly GenerateAttendeeTicketPDFService $pdfService,
    ) {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $attendeeShortId): PdfDownloadResponseDTO
    {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->findFirstWhere([
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
            ]);

        if (!$attendee || $attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
            throw new ResourceNotFoundException();
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);

        if (!$event || !$event->getOrganizer() || !$event->getEventSettings()) {
            throw new ResourceNotFoundException();
        }

        $bytes = $this->pdfService->generate($attendee, $event);

        return new PdfDownloadResponseDTO(
            bytes: $bytes,
            filename: sprintf('ticket-%s.pdf', $attendee->getPublicId()),
        );
    }
}
