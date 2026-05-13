<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Wallet;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Wallet\GoogleWalletPassService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Psr\Log\LoggerInterface;
use Throwable;

class GetGoogleWalletLinkAction extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly GoogleWalletPassService $passService,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(int $eventId, string $attendeeShortId): JsonResponse|LaravelResponse
    {
        if (!$this->config->get('wallet.google.issuer_id')) {
            return $this->notFoundResponse();
        }

        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
            ->findFirstWhere([
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
            ]);

        if (!$attendee || $attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
            return $this->notFoundResponse();
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($eventId);

        if (!$event || !$event->getOrganizer() || !$event->getEventSettings()) {
            return $this->notFoundResponse();
        }

        // Honor the per-event wallet pass toggle.
        if (!$event->getEventSettings()->getWalletPassesEnabled()) {
            return $this->notFoundResponse();
        }

        try {
            $url = $this->passService->generateSaveLink(
                attendee: $attendee,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate Google Wallet save link', [
                'event_id' => $eventId,
                'attendee_short_id' => $attendeeShortId,
                'error' => $e->getMessage(),
            ]);
            return new LaravelResponse('', 500);
        }

        return new JsonResponse(['url' => $url], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
