<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use HiEvents\Services\Domain\Pdf\DTO\PdfDownloadResponseDTO;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadOrderTicketsPublicHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly GenerateOrderTicketsPDFService $pdfService,
    ) {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $orderShortId): PdfDownloadResponseDTO
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: ProductDomainObject::class,
                        name: ProductDomainObjectAbstract::SINGULAR_NAME,
                    ),
                ],
                name: AttendeeDomainObjectAbstract::SINGULAR_NAME,
            ))
            ->findFirstWhere([
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
                OrderDomainObjectAbstract::SHORT_ID => $orderShortId,
            ]);

        if (!$order || $order->getStatus() !== OrderStatus::COMPLETED->name) {
            throw new ResourceNotFoundException();
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);

        if (!$event || !$event->getOrganizer() || !$event->getEventSettings()) {
            throw new ResourceNotFoundException();
        }

        // GenerateOrderTicketsPDFService throws ResourceNotFoundException when
        // the order has no ACTIVE attendees — let that propagate to the action
        // which converts it to a 404.
        $bytes = $this->pdfService->generate($order, $event);

        return new PdfDownloadResponseDTO(
            bytes: $bytes,
            filename: sprintf('tickets-%s.pdf', $order->getPublicId()),
        );
    }
}
