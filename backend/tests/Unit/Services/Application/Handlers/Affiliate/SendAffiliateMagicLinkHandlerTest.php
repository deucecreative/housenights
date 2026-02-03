<?php

namespace Tests\Unit\Services\Application\Handlers\Affiliate;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\SendAffiliateMagicLinkDTO;
use HiEvents\Services\Application\Handlers\Affiliate\SendAffiliateMagicLinkHandler;
use HiEvents\Services\Infrastructure\TokenGenerator\TokenGeneratorService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SendAffiliateMagicLinkHandlerTest extends TestCase
{
    private AffiliateRepositoryInterface $affiliateRepository;
    private AffiliateLoginTokenRepositoryInterface $affiliateLoginTokenRepository;
    private EventRepositoryInterface $eventRepository;
    private OrganizerSettingsRepositoryInterface $organizerSettingsRepository;
    private TokenGeneratorService $tokenGeneratorService;
    private Mailer $mailer;
    private LoggerInterface $logger;
    private DatabaseManager $databaseManager;
    private SendAffiliateMagicLinkHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateRepository = m::mock(AffiliateRepositoryInterface::class);
        $this->affiliateLoginTokenRepository = m::mock(AffiliateLoginTokenRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->organizerSettingsRepository = m::mock(OrganizerSettingsRepositoryInterface::class);
        $this->tokenGeneratorService = m::mock(TokenGeneratorService::class);
        $this->mailer = m::mock(Mailer::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->databaseManager = m::mock(DatabaseManager::class);

        $this->handler = new SendAffiliateMagicLinkHandler(
            $this->affiliateRepository,
            $this->affiliateLoginTokenRepository,
            $this->eventRepository,
            $this->organizerSettingsRepository,
            $this->tokenGeneratorService,
            $this->mailer,
            $this->logger,
            $this->databaseManager,
        );
    }

    public function testHandleSuccessfullySendsMagicLink(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 12:00:00'));

        $affiliateId = 1;
        $eventId = 10;
        $organizerId = 5;
        $dto = new SendAffiliateMagicLinkDTO(affiliateId: $affiliateId);

        $affiliate = m::mock(AffiliateDomainObject::class);
        $affiliate->shouldReceive('getId')->andReturn($affiliateId);
        $affiliate->shouldReceive('getEmail')->andReturn('affiliate@example.com');
        $affiliate->shouldReceive('getEventId')->andReturn($eventId);
        $affiliate->shouldReceive('getName')->andReturn('Test Affiliate');
        $affiliate->shouldReceive('getCode')->andReturn('TEST123');

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn($organizerId);
        $event->shouldReceive('getTitle')->andReturn('Test Event');
        $event->shouldReceive('getId')->andReturn($eventId);
        $event->shouldReceive('getSlug')->andReturn('test-event');

        $organizerSettings = m::mock(OrganizerSettingDomainObject::class);
        $organizerSettings->shouldReceive('getAffiliateTerm')->andReturn('DJ');

        $this->affiliateRepository
            ->shouldReceive('findById')
            ->once()
            ->with($affiliateId)
            ->andReturn($affiliate);

        $this->eventRepository
            ->shouldReceive('findById')
            ->once()
            ->with($eventId)
            ->andReturn($event);

        $this->organizerSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['organizer_id' => $organizerId])
            ->andReturn($organizerSettings);

        // Simulate transaction by executing the callback
        $this->databaseManager
            ->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function ($callback) {
                return $callback();
            });

        $this->tokenGeneratorService
            ->shouldReceive('generateToken')
            ->once()
            ->andReturn('aff_test_token_123');

        $this->affiliateLoginTokenRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(['affiliate_id' => $affiliateId]);

        $this->affiliateLoginTokenRepository
            ->shouldReceive('create')
            ->once();

        $this->logger
            ->shouldReceive('info')
            ->once();

        $pendingMail = m::mock(\Illuminate\Mail\PendingMail::class);
        $pendingMail->shouldReceive('queue')->once();

        $this->mailer
            ->shouldReceive('to')
            ->once()
            ->with('affiliate@example.com')
            ->andReturn($pendingMail);

        $this->handler->handle($dto);

        Carbon::setTestNow();
        
        // If we get here without exception, the test passed
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
