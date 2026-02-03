<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Mail\Affiliate\AffiliateWelcomeEmail;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\SendAffiliateMagicLinkDTO;
use HiEvents\Services\Infrastructure\TokenGenerator\TokenGeneratorService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class SendAffiliateMagicLinkHandler
{
    private const TOKEN_EXPIRY_DAYS = 7;

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
     * @throws ResourceNotFoundException
     */
    public function handle(SendAffiliateMagicLinkDTO $dto): void
    {
        $affiliate = $this->affiliateRepository->findById($dto->affiliateId);

        if (!$affiliate) {
            throw new ResourceNotFoundException(__('Affiliate not found'));
        }

        if (!$affiliate->getEmail()) {
            throw new ResourceNotFoundException(__('Affiliate does not have an email address'));
        }

        $event = $this->eventRepository->findById($affiliate->getEventId());
        $organizerSettings = $this->organizerSettingsRepository->findFirstWhere([
            'organizer_id' => $event->getOrganizerId(),
        ]);
        $affiliateTerm = $organizerSettings?->getAffiliateTerm() ?: 'Affiliate';

        $this->databaseManager->transaction(function () use ($affiliate, $event, $affiliateTerm) {
            $token = $this->generateAndSaveToken($affiliate);
            $this->sendWelcomeEmail($affiliate, $event->getTitle(), $event->getId(), $event->getSlug(), $token, $affiliateTerm);
        });
    }

    private function generateAndSaveToken(AffiliateDomainObject $affiliate): string
    {
        $token = $this->tokenGeneratorService->generateToken(prefix: 'aff');

        // Delete any existing tokens for this affiliate
        $this->affiliateLoginTokenRepository->deleteWhere([
            'affiliate_id' => $affiliate->getId(),
        ]);

        $this->affiliateLoginTokenRepository->create([
            'affiliate_id' => $affiliate->getId(),
            'token' => $token,
            'expires_at' => Carbon::now()->addDays(self::TOKEN_EXPIRY_DAYS)->toDateTimeString(),
        ]);

        return $token;
    }

    private function sendWelcomeEmail(AffiliateDomainObject $affiliate, string $eventTitle, int $eventId, string $eventSlug, string $token, string $affiliateTerm): void
    {
        $this->logger->info('Sending affiliate welcome email', [
            'affiliate_id' => $affiliate->getId(),
            'email' => $affiliate->getEmail(),
        ]);

        $baseUrl = config('app.frontend_url');
        $affiliateUrl = "{$baseUrl}/event/{$eventId}/{$eventSlug}?aff={$affiliate->getCode()}";
        $loginUrl = "{$baseUrl}/affiliate/{$token}";

        $this->mailer
            ->to($affiliate->getEmail())
            ->queue(new AffiliateWelcomeEmail(
                affiliateName: $affiliate->getName(),
                eventTitle: $eventTitle,
                affiliateCode: $affiliate->getCode(),
                affiliateUrl: $affiliateUrl,
                loginUrl: $loginUrl,
                affiliateTerm: $affiliateTerm,
            ));
    }
}
