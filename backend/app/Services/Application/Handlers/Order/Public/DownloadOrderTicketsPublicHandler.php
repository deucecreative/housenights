<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use HiEvents\Services\Domain\Pdf\DTO\PdfDownloadResponseDTO;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadOrderTicketsPublicHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly GenerateOrderTicketsPDFService $pdfService,
    ) {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $orderShortId): PdfDownloadResponseDTO
    {
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::SHORT_ID => $orderShortId,
        ]);

        if (!$order || $order->getStatus() !== OrderStatus::COMPLETED->name) {
            throw new ResourceNotFoundException();
        }

        // Load active attendees with their products attached. Done as two
        // queries (attendees + bulk products) rather than the nested
        // loadRelation chain because that chain silently dropped relations
        // on the second findWhere call in GetOrdersByLookupTokenHandler
        // (see commit e4b10b2a).
        $attendees = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: ProductDomainObjectAbstract::SINGULAR_NAME))
            ->findWhere([
                ['order_id', '=', $order->getId()],
                ['status', '=', AttendeeStatus::ACTIVE->name],
            ]);

        if ($attendees->isEmpty()) {
            throw new ResourceNotFoundException();
        }

        $order->setAttendees(new Collection($attendees->all()));

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

        $bytes = $this->pdfService->generate($order, $event);

        return new PdfDownloadResponseDTO(
            bytes: $bytes,
            filename: sprintf('tickets-%s.pdf', $order->getPublicId()),
        );
    }
}
