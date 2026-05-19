<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\Public;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use HiEvents\Services\Domain\Pdf\DTO\PdfDownloadResponseDTO;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadAttendeeTicketPublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly GenerateAttendeeTicketPDFService $pdfService,
    ) {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $attendeeShortId): PdfDownloadResponseDTO
    {
        // Load each piece explicitly rather than relying on the eager-load
        // chain. The chained loadRelation approach lost relations in
        // GetOrdersByLookupTokenHandler (see commit e4b10b2a) and produced
        // empty fields in the rendered PDF in this endpoint too, so the
        // email path's "compose-from-parts" pattern is the safer default.
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
        ]);

        if (!$attendee || $attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
            throw new ResourceNotFoundException();
        }

        $product = $this->productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => $attendee->getProductId(),
        ]);
        if ($product) {
            $attendee->setProduct($product);
        }

        $event = $this->eventRepository->findFirstWhere([
            EventDomainObjectAbstract::ID => $eventId,
        ]);
        if (!$event) {
            throw new ResourceNotFoundException();
        }

        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            EventSettingDomainObjectAbstract::EVENT_ID => $eventId,
        ]);
        if (!$eventSettings) {
            throw new ResourceNotFoundException();
        }
        $event->setEventSettings($eventSettings);

        $organizer = $this->organizerRepository->findFirstWhere([
            OrganizerDomainObjectAbstract::ID => $event->getOrganizerId(),
        ]);
        if (!$organizer) {
            throw new ResourceNotFoundException();
        }
        $event->setOrganizer($organizer);

        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::ID => $attendee->getOrderId(),
        ]);

        $bytes = $this->pdfService->generate($attendee, $event, $order);

        return new PdfDownloadResponseDTO(
            bytes: $bytes,
            filename: sprintf('ticket-%s.pdf', $attendee->getPublicId()),
        );
    }
}
