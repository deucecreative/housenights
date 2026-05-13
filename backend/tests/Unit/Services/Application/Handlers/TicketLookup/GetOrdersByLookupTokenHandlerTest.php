<?php

namespace Tests\Unit\Services\Application\Handlers\TicketLookup;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\TicketLookupTokenDomainObject;
use HiEvents\Exceptions\InvalidTicketLookupTokenException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\TicketLookupTokenRepositoryInterface;
use HiEvents\Services\Application\Handlers\TicketLookup\DTO\GetOrdersByLookupTokenDTO;
use HiEvents\Services\Application\Handlers\TicketLookup\GetOrdersByLookupTokenHandler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class GetOrdersByLookupTokenHandlerTest extends TestCase
{
    private TicketLookupTokenRepositoryInterface $ticketLookupTokenRepository;
    private OrderRepositoryInterface $orderRepository;
    private DatabaseManager $databaseManager;
    private GetOrdersByLookupTokenHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketLookupTokenRepository = m::mock(TicketLookupTokenRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->databaseManager = m::mock(DatabaseManager::class);

        $this->handler = new GetOrdersByLookupTokenHandler(
            $this->ticketLookupTokenRepository,
            $this->orderRepository,
            $this->databaseManager,
        );
    }

    private function stubAttendeeLookup(string $email, array $orderIds): void
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

    public function testHandleSuccessfullyReturnsOrdersWhenTokenIsValid(): void
    {
        $token = 'tl_validtoken123';
        $email = 'test@example.com';
        $dto = new GetOrdersByLookupTokenDTO(token: $token);

        $tokenRecord = m::mock(TicketLookupTokenDomainObject::class);
        $tokenRecord->shouldReceive('getExpiresAt')
            ->andReturn(Carbon::now()->addHours(12)->toDateTimeString());
        $tokenRecord->shouldReceive('getEmail')
            ->andReturn($email);

        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        $order->shouldReceive('getEmail')->andReturn($email);
        $order->shouldReceive('getAttendees')->andReturn(null);

        $orders = new Collection([$order]);

        $this->ticketLookupTokenRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['token' => $token])
            ->andReturn($tokenRecord);

        $this->stubAttendeeLookup($email, []);

        $this->orderRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf();

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($orders);

        $result = $this->handler->handle($dto);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        // Purchaser scope — not flagged.
        $this->assertFalse($result->first()->isAttendeeScope);
    }

    public function testHandleThrowsExceptionWhenTokenNotFound(): void
    {
        $token = 'tl_invalidtoken';
        $dto = new GetOrdersByLookupTokenDTO(token: $token);

        $this->ticketLookupTokenRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['token' => $token])
            ->andReturn(null);

        $this->orderRepository
            ->shouldNotReceive('findWhere');

        $this->expectException(InvalidTicketLookupTokenException::class);
        $this->expectExceptionMessage('Invalid or expired link. Please request a new one.');

        $this->handler->handle($dto);
    }

    public function testHandleThrowsExceptionWhenTokenIsExpired(): void
    {
        $token = 'tl_expiredtoken';
        $dto = new GetOrdersByLookupTokenDTO(token: $token);

        $tokenRecord = m::mock(TicketLookupTokenDomainObject::class);
        $tokenRecord->shouldReceive('getExpiresAt')
            ->andReturn(Carbon::now()->subHours(2)->toDateTimeString());

        $this->ticketLookupTokenRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['token' => $token])
            ->andReturn($tokenRecord);

        $this->orderRepository
            ->shouldNotReceive('findWhere');

        $this->expectException(InvalidTicketLookupTokenException::class);
        $this->expectExceptionMessage('This link has expired. Please request a new one.');

        $this->handler->handle($dto);
    }

    public function testPurchaserSeesFullOrderWithAllAttendees(): void
    {
        $email = 'purchaser@example.com';
        $token = 'tl_purchaser';
        $dto = new GetOrdersByLookupTokenDTO(token: $token);

        $tokenRecord = m::mock(TicketLookupTokenDomainObject::class);
        $tokenRecord->shouldReceive('getExpiresAt')->andReturn(Carbon::now()->addHour()->toDateTimeString());
        $tokenRecord->shouldReceive('getEmail')->andReturn($email);

        $attendeeA = m::mock(AttendeeDomainObject::class);
        $attendeeA->shouldReceive('getEmail')->andReturn($email);
        $attendeeB = m::mock(AttendeeDomainObject::class);
        $attendeeB->shouldReceive('getEmail')->andReturn('friend@example.com');

        $attendees = new Collection([$attendeeA, $attendeeB]);

        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(101);
        $order->shouldReceive('getEmail')->andReturn($email);
        $order->shouldReceive('getAttendees')->andReturn($attendees);
        // Should NOT be mutated in purchaser-mode.
        $order->shouldNotReceive('setAttendees');
        $order->shouldNotReceive('setFirstName');
        $order->shouldNotReceive('setLastName');
        $order->shouldNotReceive('setEmail');
        $order->shouldNotReceive('setAddress');

        $this->ticketLookupTokenRepository
            ->shouldReceive('findFirstWhere')->andReturn($tokenRecord);

        $this->stubAttendeeLookup($email, []);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection([$order]));

        $result = $this->handler->handle($dto);

        $this->assertCount(1, $result);
        $this->assertFalse($result->first()->isAttendeeScope);
    }

    public function testAttendeeOnlyScopeMasksPurchaserPiiAndFiltersAttendees(): void
    {
        $lookupEmail = 'attendee@example.com';
        $purchaserEmail = 'purchaser@example.com';
        $token = 'tl_attendee';
        $dto = new GetOrdersByLookupTokenDTO(token: $token);

        $tokenRecord = m::mock(TicketLookupTokenDomainObject::class);
        $tokenRecord->shouldReceive('getExpiresAt')->andReturn(Carbon::now()->addHour()->toDateTimeString());
        $tokenRecord->shouldReceive('getEmail')->andReturn($lookupEmail);

        $matchingAttendee = m::mock(AttendeeDomainObject::class);
        $matchingAttendee->shouldReceive('getEmail')->andReturn($lookupEmail);

        $otherAttendee = m::mock(AttendeeDomainObject::class);
        $otherAttendee->shouldReceive('getEmail')->andReturn('someone@else.com');

        $attendees = new Collection([$matchingAttendee, $otherAttendee]);

        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(202);
        $order->shouldReceive('getEmail')->andReturn($purchaserEmail);
        $order->shouldReceive('getAttendees')->andReturn($attendees);

        // Expect masking + filtering in attendee scope.
        $capturedScopedAttendees = null;
        $order->shouldReceive('setAttendees')
            ->once()
            ->with(m::on(function ($scoped) use (&$capturedScopedAttendees, $lookupEmail) {
                $capturedScopedAttendees = $scoped;
                return $scoped instanceof Collection
                    && $scoped->count() === 1
                    && strtolower($scoped->first()->getEmail()) === $lookupEmail;
            }));
        $order->shouldReceive('setFirstName')->once()->with('');
        $order->shouldReceive('setLastName')->once()->with('');
        $order->shouldReceive('setEmail')->once()->with(null);
        $order->shouldReceive('setAddress')->once()->with(null);

        $this->ticketLookupTokenRepository
            ->shouldReceive('findFirstWhere')->andReturn($tokenRecord);

        $this->stubAttendeeLookup($lookupEmail, [202]);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        // No purchaser-email match:
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->withArgs(function ($conditions, $columns = ['*'], $orderAndDirections = []) use ($lookupEmail) {
                return is_array($conditions)
                    && $conditions[0][0] === 'email'
                    && $conditions[0][2] === $lookupEmail;
            })
            ->andReturn(new Collection());
        // Attendee-id match returns this order:
        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->withArgs(function ($conditions, $columns = ['*'], $orderAndDirections = []) {
                return is_array($conditions) && $conditions[0][1] === 'in';
            })
            ->andReturn(new Collection([$order]));

        $result = $this->handler->handle($dto);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->isAttendeeScope);
        $this->assertNotNull($capturedScopedAttendees);
        $this->assertCount(1, $capturedScopedAttendees);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
