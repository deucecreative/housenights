<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate;

use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\InvalidAffiliateMagicLinkException;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\GetAffiliateByMagicLinkDTO;
use Illuminate\Support\Collection;

class GetAffiliateByMagicLinkHandler
{
    public function __construct(
        private readonly AffiliateLoginTokenRepositoryInterface $affiliateLoginTokenRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {
    }

    /**
     * @throws InvalidAffiliateMagicLinkException
     */
    public function handle(GetAffiliateByMagicLinkDTO $dto): array
    {
        $token = $this->affiliateLoginTokenRepository->findValidByToken($dto->token);

        if (!$token) {
            throw new InvalidAffiliateMagicLinkException(__('Invalid or expired link. Please request a new magic link.'));
        }

        $affiliate = $this->affiliateRepository->findById($token->getAffiliateId());

        if (!$affiliate) {
            throw new InvalidAffiliateMagicLinkException(__('Affiliate not found.'));
        }

        $event = $this->eventRepository->findById($affiliate->getEventId());
        
        // Load organizer with settings for affiliate_term
        $organizer = $this->organizerRepository
            ->loadRelation(OrganizerSettingDomainObject::class)
            ->findFirstWhere(['id' => $event->getOrganizerId()]);
        
        $orders = $this->getAffiliateOrders($affiliate);

        return [
            'affiliate' => $affiliate,
            'event' => $event,
            'organizer' => $organizer,
            'orders' => $orders,
        ];
    }

    /**
     * Get orders for this affiliate with buyer name formatted as "First Name + Last Initial"
     */
    private function getAffiliateOrders(AffiliateDomainObject $affiliate): Collection
    {
        $orders = $this->orderRepository->findWhere([
            [OrderDomainObjectAbstract::AFFILIATE_ID, '=', $affiliate->getId()],
            [OrderDomainObjectAbstract::STATUS, '=', OrderStatus::COMPLETED->name],
        ]);

        // Transform orders to include formatted buyer name (first name + last initial)
        return $orders->map(function (OrderDomainObject $order) {
            return [
                'id' => $order->getId(),
                'short_id' => $order->getShortId(),
                'buyer_name' => $this->formatBuyerName($order->getFirstName(), $order->getLastName()),
                'total_gross' => $order->getTotalGross(),
                'created_at' => $order->getCreatedAt(),
            ];
        });
    }

    /**
     * Format buyer name as "FirstName L." for privacy
     */
    private function formatBuyerName(?string $firstName, ?string $lastName): string
    {
        $firstName = $firstName ?? '';
        $lastInitial = $lastName ? mb_substr($lastName, 0, 1) . '.' : '';

        return trim($firstName . ' ' . $lastInitial);
    }
}
