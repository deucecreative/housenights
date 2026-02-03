<?php

namespace Tests\Unit\Services\Application\Handlers\Affiliate;

use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\AffiliateLoginTokenDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Exceptions\InvalidAffiliateMagicLinkException;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\GetAffiliateByMagicLinkDTO;
use HiEvents\Services\Application\Handlers\Affiliate\GetAffiliateByMagicLinkHandler;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class GetAffiliateByMagicLinkHandlerTest extends TestCase
{
    private AffiliateLoginTokenRepositoryInterface $affiliateLoginTokenRepository;
    private AffiliateRepositoryInterface $affiliateRepository;
    private EventRepositoryInterface $eventRepository;
    private OrderRepositoryInterface $orderRepository;
    private OrganizerRepositoryInterface $organizerRepository;
    private GetAffiliateByMagicLinkHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateLoginTokenRepository = m::mock(AffiliateLoginTokenRepositoryInterface::class);
        $this->affiliateRepository = m::mock(AffiliateRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);

        $this->handler = new GetAffiliateByMagicLinkHandler(
            $this->affiliateLoginTokenRepository,
            $this->affiliateRepository,
            $this->eventRepository,
            $this->orderRepository,
            $this->organizerRepository,
        );
    }

    public function testHandleSuccessfullyReturnsAffiliateData(): void
    {
        $tokenString = 'aff_valid_token_123';
        $affiliateId = 1;
        $eventId = 10;
        $organizerId = 5;
        $dto = new GetAffiliateByMagicLinkDTO(token: $tokenString);

        $token = m::mock(AffiliateLoginTokenDomainObject::class);
        $token->shouldReceive('getAffiliateId')->andReturn($affiliateId);

        $affiliate = m::mock(AffiliateDomainObject::class);
        $affiliate->shouldReceive('getId')->andReturn($affiliateId);
        $affiliate->shouldReceive('getEventId')->andReturn($eventId);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn($organizerId);

        $organizer = m::mock(OrganizerDomainObject::class);

        $this->affiliateLoginTokenRepository
            ->shouldReceive('findValidByToken')
            ->once()
            ->with($tokenString)
            ->andReturn($token);

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

        $this->organizerRepository
            ->shouldReceive('loadRelation')
            ->once()
            ->with(OrganizerSettingDomainObject::class)
            ->andReturnSelf();

        $this->organizerRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => $organizerId])
            ->andReturn($organizer);

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection());

        $result = $this->handler->handle($dto);

        $this->assertArrayHasKey('affiliate', $result);
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('organizer', $result);
        $this->assertArrayHasKey('orders', $result);
        $this->assertSame($affiliate, $result['affiliate']);
        $this->assertSame($event, $result['event']);
        $this->assertSame($organizer, $result['organizer']);
    }

    public function testHandleThrowsExceptionWhenTokenInvalid(): void
    {
        $dto = new GetAffiliateByMagicLinkDTO(token: 'invalid_token');

        $this->affiliateLoginTokenRepository
            ->shouldReceive('findValidByToken')
            ->once()
            ->with('invalid_token')
            ->andReturn(null);

        $this->expectException(InvalidAffiliateMagicLinkException::class);
        $this->expectExceptionMessage('Invalid or expired link');

        $this->handler->handle($dto);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
