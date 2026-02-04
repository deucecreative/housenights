<?php

namespace Tests\Unit\Services\Application\Handlers\Affiliate;

use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\Status\AffiliateStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Services\Application\Handlers\Affiliate\CreateAffiliateHandler;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\UpsertAffiliateDTO;
use HiEvents\Services\Application\Handlers\Affiliate\SendAffiliateMagicLinkHandler;
use Mockery as m;
use Tests\TestCase;

class CreateAffiliateHandlerTest extends TestCase
{
    private AffiliateRepositoryInterface $affiliateRepository;
    private SendAffiliateMagicLinkHandler $sendAffiliateMagicLinkHandler;
    private CreateAffiliateHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateRepository = m::mock(AffiliateRepositoryInterface::class);
        $this->sendAffiliateMagicLinkHandler = m::mock(SendAffiliateMagicLinkHandler::class);
        $this->handler = new CreateAffiliateHandler(
            $this->affiliateRepository,
            $this->sendAffiliateMagicLinkHandler
        );
    }

    public function testHandleSuccessfullyCreatesAffiliate(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'test123',
            email: 'test@example.com',
            status: AffiliateStatus::ACTIVE
        );

        $expectedCode = 'TEST123';
        $expectedAffiliate = m::mock(AffiliateDomainObject::class);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => $eventId,
                'code' => $expectedCode,
            ])
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'event_id' => $eventId,
                'account_id' => $accountId,
                'name' => 'Test Affiliate',
                'code' => $expectedCode,
                'email' => 'test@example.com',
                'status' => AffiliateStatus::ACTIVE->value,
            ])
            ->andReturn($expectedAffiliate);

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    public function testHandleSuccessfullyCreatesAffiliateWithoutEmail(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'test123',
            email: null,
            status: AffiliateStatus::INACTIVE
        );

        $expectedCode = 'TEST123';
        $expectedAffiliate = m::mock(AffiliateDomainObject::class);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => $eventId,
                'code' => $expectedCode,
            ])
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'event_id' => $eventId,
                'account_id' => $accountId,
                'name' => 'Test Affiliate',
                'code' => $expectedCode,
                'email' => null,
                'status' => AffiliateStatus::INACTIVE->value,
            ])
            ->andReturn($expectedAffiliate);

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    public function testHandleConvertsCodeToUppercase(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'lowercase_code',
            email: 'test@example.com',
            status: AffiliateStatus::ACTIVE
        );

        $expectedCode = 'LOWERCASE_CODE';
        $expectedAffiliate = m::mock(AffiliateDomainObject::class);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => $eventId,
                'code' => $expectedCode,
            ])
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'event_id' => $eventId,
                'account_id' => $accountId,
                'name' => 'Test Affiliate',
                'code' => $expectedCode,
                'email' => 'test@example.com',
                'status' => AffiliateStatus::ACTIVE->value,
            ])
            ->andReturn($expectedAffiliate);

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    public function testHandleThrowsExceptionWhenAffiliateCodeAlreadyExists(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'existing_code',
            email: 'test@example.com',
            status: AffiliateStatus::ACTIVE
        );

        $expectedCode = 'EXISTING_CODE';
        $existingAffiliate = m::mock(AffiliateDomainObject::class);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => $eventId,
                'code' => $expectedCode,
            ])
            ->andReturn($existingAffiliate);

        $this->affiliateRepository
            ->shouldNotReceive('create');

        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('An affiliate with this code already exists for this event');

        $this->handler->handle($eventId, $accountId, $dto);
    }

    public function testHandleSendsMagicLinkWhenAutoSendIsTrue(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'test123',
            email: 'test@example.com',
            status: AffiliateStatus::ACTIVE,
            autoSendMagicLink: true
        );

        $expectedCode = 'TEST123';
        $expectedAffiliate = m::mock(AffiliateDomainObject::class);
        $expectedAffiliate->shouldReceive('getId')->andReturn(99);
        $expectedAffiliate->shouldReceive('getEmail')->andReturn('test@example.com');

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($expectedAffiliate);

        $this->sendAffiliateMagicLinkHandler
            ->shouldReceive('handle')
            ->once()
            ->with(m::type(\HiEvents\Services\Application\Handlers\Affiliate\DTO\SendAffiliateMagicLinkDTO::class));

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    public function testHandleDoesNotSendMagicLinkWhenAutoSendIsFalse(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'test123',
            email: 'test@example.com',
            status: AffiliateStatus::ACTIVE,
            autoSendMagicLink: false
        );

        $expectedAffiliate = m::mock(AffiliateDomainObject::class);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($expectedAffiliate);

        $this->sendAffiliateMagicLinkHandler
            ->shouldNotReceive('handle');

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    public function testHandleDoesNotSendMagicLinkWhenNoEmail(): void
    {
        $eventId = 1;
        $accountId = 2;
        $dto = new UpsertAffiliateDTO(
            name: 'Test Affiliate',
            code: 'test123',
            email: null,
            status: AffiliateStatus::ACTIVE,
            autoSendMagicLink: true
        );

        $expectedAffiliate = m::mock(AffiliateDomainObject::class);
        $expectedAffiliate->shouldReceive('getEmail')->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(null);

        $this->affiliateRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($expectedAffiliate);

        $this->sendAffiliateMagicLinkHandler
            ->shouldNotReceive('handle');

        $result = $this->handler->handle($eventId, $accountId, $dto);

        $this->assertSame($expectedAffiliate, $result);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}