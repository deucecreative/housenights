<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Public\DownloadOrderTicketsPublicHandler;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use Illuminate\Support\Collection;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class DownloadOrderTicketsPublicHandlerTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private EventRepositoryInterface $eventRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private OrganizerRepositoryInterface $organizerRepository;
    private GenerateOrderTicketsPDFService $pdfService;
    private DownloadOrderTicketsPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $this->pdfService = m::mock(GenerateOrderTicketsPDFService::class);

        $this->handler = new DownloadOrderTicketsPublicHandler(
            $this->orderRepository,
            $this->attendeeRepository,
            $this->eventRepository,
            $this->eventSettingsRepository,
            $this->organizerRepository,
            $this->pdfService,
        );
    }

    public function testReturnsPdfBytesAndFilenameForCompletedOrder(): void
    {
        $order = $this->mockCompletedOrder(id: 11);
        $attendees = new Collection([m::mock(AttendeeDomainObject::class)]);
        $order->shouldReceive('setAttendees')->once();

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn(99);
        $event->shouldReceive('setEventSettings')->once();
        $event->shouldReceive('setOrganizer')->once();
        $settings = m::mock(EventSettingDomainObject::class);
        $organizer = m::mock(OrganizerDomainObject::class);

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 42, 'short_id' => 'o_abc'])
            ->andReturn($order);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository
            ->shouldReceive('findWhere')->once()->andReturn($attendees);

        $this->eventRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 42])
            ->andReturn($event);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 42])
            ->andReturn($settings);

        $this->organizerRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 99])
            ->andReturn($organizer);

        $this->pdfService
            ->shouldReceive('generate')
            ->once()
            ->with($order, $event)
            ->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 42, orderShortId: 'o_abc');

        $this->assertSame('%PDF-bytes', $result->bytes);
        $this->assertSame('tickets-O-PUB-1.pdf', $result->filename);
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, orderShortId: 'missing');
    }

    public function testThrowsWhenOrderNotCompleted(): void
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::RESERVED->name);

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, orderShortId: 'o_abc');
    }

    public function testThrowsWhenOrderHasNoActiveAttendees(): void
    {
        $order = $this->mockCompletedOrder(id: 11);

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection());

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, orderShortId: 'o_abc');
    }

    public function testThrowsWhenEventNotFound(): void
    {
        $order = $this->mockCompletedOrder(id: 11);
        $order->shouldReceive('setAttendees');

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->andReturn(new Collection([m::mock(AttendeeDomainObject::class)]));

        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, orderShortId: 'o_abc');
    }

    public function testCrossEventOrderShortIdRejectedByRepositoryQuery(): void
    {
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->withArgs(fn($conditions) =>
                $conditions['event_id'] === 99 && $conditions['short_id'] === 'o_from_event_1'
            )
            ->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 99, orderShortId: 'o_from_event_1');
    }

    public function testGeneratesPdfWhenWalletEnabled(): void
    {
        $this->assertPdfGeneratesForWalletFlag(walletEnabled: true);
    }

    public function testGeneratesPdfWhenWalletDisabled(): void
    {
        $this->assertPdfGeneratesForWalletFlag(walletEnabled: false);
    }

    private function assertPdfGeneratesForWalletFlag(bool $walletEnabled): void
    {
        $order = $this->mockCompletedOrder(id: 11);
        $order->shouldReceive('setAttendees');

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn(99);
        $event->shouldReceive('setEventSettings');
        $event->shouldReceive('setOrganizer');
        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getWalletPassesEnabled')->andReturn($walletEnabled);
        $organizer = m::mock(OrganizerDomainObject::class);

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(
            new Collection([m::mock(AttendeeDomainObject::class)])
        );
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($event);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($settings);
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn($organizer);

        $this->pdfService->shouldReceive('generate')->once()->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 1, orderShortId: 'o_x');

        $this->assertSame('%PDF-bytes', $result->bytes);
    }

    private function mockCompletedOrder(int $id): OrderDomainObject
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::COMPLETED->name);
        $order->shouldReceive('getId')->andReturn($id);
        $order->shouldReceive('getPublicId')->andReturn('O-PUB-1');

        return $order;
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
