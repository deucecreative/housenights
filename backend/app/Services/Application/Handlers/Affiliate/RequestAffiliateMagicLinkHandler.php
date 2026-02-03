<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AffiliateDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AffiliateStatus;
use HiEvents\Mail\Affiliate\AffiliateMagicLinkEmail;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\RequestAffiliateMagicLinkDTO;
use HiEvents\Services\Infrastructure\TokenGenerator\TokenGeneratorService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

class RequestAffiliateMagicLinkHandler
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly AffiliateLoginTokenRepositoryInterface $affiliateLoginTokenRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly TokenGeneratorService $tokenGeneratorService,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $databaseManager,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(RequestAffiliateMagicLinkDTO $dto): void
    {
        $email = strtolower($dto->email);

        $affiliates = $this->findActiveAffiliatesByEmail($email);

        if ($affiliates->isEmpty()) {
            $this->logger->info('Affiliate magic link requested for email with no affiliates', [
                'email' => $email,
            ]);
            return;
        }

        $this->databaseManager->transaction(function () use ($email, $affiliates) {
            // Send a magic link for each affiliate (each event they're affiliated with)
            foreach ($affiliates as $affiliate) {
                $event = $this->eventRepository->findById($affiliate->getEventId());
                if ($event) {
                    $organizerSettings = $this->organizerSettingsRepository->findFirstWhere([
                        'organizer_id' => $event->getOrganizerId(),
                    ]);
                    $affiliateTerm = $organizerSettings?->getAffiliateTerm() ?: 'Affiliate';
                    $this->generateAndSendMagicLink($affiliate, $event, $email, $affiliateTerm);
                }
            }
        });
    }

    private function findActiveAffiliatesByEmail(string $email): Collection
    {
        return $this->affiliateRepository->findWhere([
            [AffiliateDomainObjectAbstract::EMAIL, '=', $email],
            [AffiliateDomainObjectAbstract::STATUS, '=', AffiliateStatus::ACTIVE->value],
        ]);
    }

    private function generateAndSendMagicLink(
        AffiliateDomainObject $affiliate,
        EventDomainObject $event,
        string $email,
        string $affiliateTerm
    ): void {
        $token = $this->tokenGeneratorService->generateToken(prefix: 'aff');

        // Delete any existing tokens for this affiliate
        $this->affiliateLoginTokenRepository->deleteWhere(['affiliate_id' => $affiliate->getId()]);

        // Create new token
        $this->affiliateLoginTokenRepository->create([
            'affiliate_id' => $affiliate->getId(),
            'token' => $token,
            'expires_at' => Carbon::now()->addHours(self::TOKEN_EXPIRY_HOURS)->toDateTimeString(),
        ]);

        $this->logger->info('Sending affiliate magic link email', [
            'email' => $email,
            'affiliate_id' => $affiliate->getId(),
            'event_id' => $event->getId(),
        ]);

        $this->mailer
            ->to($email)
            ->queue(new AffiliateMagicLinkEmail(
                affiliateName: $affiliate->getName(),
                eventTitle: $event->getTitle(),
                token: $token,
                affiliateTerm: $affiliateTerm,
            ));
    }
}
