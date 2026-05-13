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
use HiEvents\Services\Domain\Wallet\AppleWalletPassService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Response as LaravelResponse;
use Psr\Log\LoggerInterface;
use Throwable;

class DownloadApplePassAction extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AppleWalletPassService $passService,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(int $eventId, string $attendeeShortId): LaravelResponse
    {
        // Defensive: bail early if Apple Wallet isn't configured for this install.
        if (!$this->config->get('wallet.apple.pass_type_id')) {
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

        try {
            $bytes = $this->passService->generatePass(
                attendee: $attendee,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate Apple Wallet pass', [
                'event_id' => $eventId,
                'attendee_short_id' => $attendeeShortId,
                'error' => $e->getMessage(),
            ]);
            return new LaravelResponse('', 500);
        }

        return new LaravelResponse($bytes, 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="ticket.pkpass"',
            'Content-Length' => (string)strlen($bytes),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
