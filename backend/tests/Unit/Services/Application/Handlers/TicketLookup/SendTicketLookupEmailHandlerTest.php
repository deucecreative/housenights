<?php

namespace Tests\Unit\Services\Application\Handlers\TicketLookup;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\TicketLookupTokenRepositoryInterface;
use HiEvents\Services\Application\Handlers\TicketLookup\DTO\SendTicketLookupEmailDTO;
use HiEvents\Services\Application\Handlers\TicketLookup\SendTicketLookupEmailHandler;
use HiEvents\Services\Infrastructure\TokenGenerator\TokenGeneratorService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SendTicketLookupEmailHandlerTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private TicketLookupTokenRepositoryInterface $ticketLookupTokenRepository;
    private TokenGeneratorService $tokenGeneratorService;
    private Mailer $mailer;
    private LoggerInterface $logger;
    private DatabaseManager $databaseManager;
    private SendTicketLookupEmailHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->ticketLookupTokenRepository = m::mock(TicketLookupTokenRepositoryInterface::class);
        $this->tokenGeneratorService = m::mock(TokenGeneratorService::class);
        $this->mailer = m::mock(Mailer::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->databaseManager = m::mock(DatabaseManager::class);

        $this->handler = new SendTicketLookupEmailHandler(
            $this->orderRepository,
            $this->ticketLookupTokenRepository,
            $this->tokenGeneratorService,
            $this->mailer,
            $this->logger,
            $this->databaseManager,
        );
    }

    /**
     * Stub the attendee-email lookup so it returns an empty list of order ids.
     */
    private function stubAttendeeLookupEmpty(string $email): void
    {
        $query = m::mock();
        $query->shouldReceive('where')->with('email', $email)->andReturnSelf();
        $query->shouldReceive('where')->with('status', AttendeeStatus::ACTIVE->name)->andReturnSelf();
        $query->shouldReceive('whereNull')->with('deleted_at')->andReturnSelf();
        $query->shouldReceive('pluck')->with('order_id')->andReturn(new Collection([]));

        $this->databaseManager
            ->shouldReceive('table')
            ->with('attendees')
            ->andReturn($query);
    }

    /**
     * Stub the attendee-email lookup so it returns the given order ids.
     *
     * @param int[] $orderIds
     */
    private function stubAttendeeLookupReturning(string $email, array $orderIds): void
    {
        $query = m::mock();
        $query->shouldReceive('where')->with('email', $email)->andReturnSelf();
        $query->shouldReceive('where')->with('status', AttendeeStatus::ACTIVE->name)->andReturnSelf();
        $query->shouldReceive('whereNull')->with('deleted_at')->andReturnSelf();
        $query->shouldReceive('pluck')->with('order_id')->andReturn(new Collection($orderIds));

        $this->databaseManager
            ->shouldReceive('table')
            ->with('attendees')
            ->andReturn($query);
    }

    public function testHandleSuccessfullySendsEmailWhenOrdersExist(): void
    {
        $email = 'test@example.com';
        $dto = new SendTicketLookupEmailDTO(email: $email);
        $token = 'tl_test123';

        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        $orders = new Collection([$order]);

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($orders);

        $this->stubAttendeeLookupEmpty($email);

        $this->databaseManager
            ->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function ($callback) {
                return $callback();
            });

        $this->ticketLookupTokenRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(['email' => $email]);

        $this->tokenGeneratorService
            ->shouldReceive('generateToken')
            ->once()
            ->andReturn($token);

        $this->ticketLookupTokenRepository
            ->shouldReceive('create')
            ->once();

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Sending ticket lookup email', m::any());

        $pendingMail = m::mock('PendingMail');
        $this->mailer
            ->shouldReceive('to')
            ->once()
            ->with($email)
            ->andReturn($pendingMail);

        $pendingMail
            ->shouldReceive('queue')
            ->once()
            ->with(m::type(TicketLookupEmail::class));

        $this->handler->handle($dto);

        $this->assertTrue(true);
    }

    public function testHandleDoesNotSendEmailWhenNoOrdersExist(): void
    {
        $email = 'test@example.com';
        $dto = new SendTicketLookupEmailDTO(email: $email);

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection());

        $this->stubAttendeeLookupEmpty($email);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Ticket lookup requested for email with no orders', ['email' => $email]);

        $this->databaseManager
            ->shouldNotReceive('transaction');

        $this->ticketLookupTokenRepository
            ->shouldNotReceive('create');

        $this->mailer
            ->shouldNotReceive('to');

        $this->handler->handle($dto);

        $this->assertTrue(true);
    }

    public function testHandleConvertsEmailToLowercase(): void
    {
        $email = 'TEST@EXAMPLE.COM';
        $expectedLowercaseEmail = 'test@example.com';
        $dto = new SendTicketLookupEmailDTO(email: $email);

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection());

        $this->stubAttendeeLookupEmpty($expectedLowercaseEmail);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Ticket lookup requested for email with no orders', ['email' => $expectedLowercaseEmail]);

        $this->handler->handle($dto);

        $this->assertTrue(true);
    }

    public function testHandleSendsEmailWhenOnlyAttendeeMatches(): void
    {
        $email = 'attendee@example.com';
        $dto = new SendTicketLookupEmailDTO(email: $email);
        $token = 'tl_attendee123';

        // Purchaser-email query returns no orders.
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(m::on(function ($conditions) use ($email) {
                return $conditions[0][0] === 'email' && $conditions[0][2] === $email;
            }))
            ->andReturn(new Collection());

        // Attendee-email lookup returns order id 42.
        $this->stubAttendeeLookupReturning($email, [42]);

        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(42);

        // Second findWhere call for the attendee-matched order ids.
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(m::on(function ($conditions) {
                return $conditions[0][1] === 'in' && in_array(42, $conditions[0][2], true);
            }))
            ->andReturn(new Collection([$order]));

        $this->databaseManager
            ->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($cb) => $cb());

        $this->ticketLookupTokenRepository->shouldReceive('deleteWhere')->once();
        $this->ticketLookupTokenRepository->shouldReceive('create')->once();
        $this->tokenGeneratorService->shouldReceive('generateToken')->andReturn($token);

        $this->logger->shouldReceive('info')->once();

        $pendingMail = m::mock('PendingMail');
        $this->mailer->shouldReceive('to')->with($email)->andReturn($pendingMail);
        $pendingMail->shouldReceive('queue')->once()->with(m::type(TicketLookupEmail::class));

        $this->handler->handle($dto);

        $this->assertTrue(true);
    }

    public function testHandleDedupesOrdersWhenBothPurchaserAndAttendeeMatch(): void
    {
        $email = 'shared@example.com';
        $dto = new SendTicketLookupEmailDTO(email: $email);

        // Same order id 7 in both purchaser and attendee matches.
        $orderA = m::mock(OrderDomainObject::class);
        $orderA->shouldReceive('getId')->andReturn(7);

        $orderB = m::mock(OrderDomainObject::class);
        $orderB->shouldReceive('getId')->andReturn(7);

        // Purchaser query returns the order.
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(m::on(fn ($c) => $c[0][0] === 'email'))
            ->andReturn(new Collection([$orderA]));

        // Attendee lookup returns the same order id.
        $this->stubAttendeeLookupReturning($email, [7]);

        // Second findWhere returns the same order id.
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(m::on(fn ($c) => $c[0][1] === 'in'))
            ->andReturn(new Collection([$orderB]));

        $capturedOrderCount = null;

        $this->databaseManager
            ->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($cb) => $cb());

        $this->ticketLookupTokenRepository->shouldReceive('deleteWhere')->once();
        $this->ticketLookupTokenRepository->shouldReceive('create')->once();
        $this->tokenGeneratorService->shouldReceive('generateToken')->andReturn('tl_x');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Sending ticket lookup email', m::on(function ($ctx) use (&$capturedOrderCount) {
                $capturedOrderCount = $ctx['order_count'] ?? null;
                return true;
            }));

        $pendingMail = m::mock('PendingMail');
        $this->mailer->shouldReceive('to')->andReturn($pendingMail);
        $pendingMail->shouldReceive('queue')->once();

        $this->handler->handle($dto);

        // Deduped — order 7 should only count once.
        $this->assertSame(1, $capturedOrderCount);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
