<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order\Public;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Public\DownloadOrderTicketsPublicHandler;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class DownloadOrderTicketsPublicHandlerTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private EventRepositoryInterface $eventRepository;
    private GenerateOrderTicketsPDFService $pdfService;
    private DownloadOrderTicketsPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->pdfService = m::mock(GenerateOrderTicketsPDFService::class);

        $this->handler = new DownloadOrderTicketsPublicHandler(
            $this->orderRepository,
            $this->eventRepository,
            $this->pdfService,
        );
    }

    public function testReturnsPdfBytesAndFilenameForCompletedOrder(): void
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::COMPLETED->name);
        $order->shouldReceive('getPublicId')->andReturn('O-PUB-1');

        $event = $this->mockEventWithRelations();

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => 42,
                'short_id' => 'o_abc',
            ])
            ->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirstWhere')->once()->with(['id' => 42])->andReturn($event);

        $this->pdfService->shouldReceive('generate')
            ->once()
            ->with($order, $event)
            ->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 42, orderShortId: 'o_abc');

        $this->assertSame('%PDF-bytes', $result->bytes);
        $this->assertSame('tickets-O-PUB-1.pdf', $result->filename);
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 42, orderShortId: 'missing');
    }

    public function testThrowsWhenOrderNotCompleted(): void
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::RESERVED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 42, orderShortId: 'o_abc');
    }

    public function testThrowsWhenEventNotFound(): void
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::COMPLETED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 42, orderShortId: 'o_abc');
    }

    public function testCrossEventOrderShortIdRejectedByRepositoryQuery(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->withArgs(function ($conditions) {
                return $conditions['event_id'] === 99 && $conditions['short_id'] === 'o_from_event_1';
            })
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
        // PDF download is independent of the wallet_passes_enabled flag.
        $this->assertPdfGeneratesForWalletFlag(walletEnabled: false);
    }

    private function assertPdfGeneratesForWalletFlag(bool $walletEnabled): void
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::COMPLETED->name);
        $order->shouldReceive('getPublicId')->andReturn('O-1');

        $event = $this->mockEventWithRelations(walletEnabled: $walletEnabled);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($event);

        $this->pdfService->shouldReceive('generate')->once()->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 1, orderShortId: 'o_x');

        $this->assertSame('%PDF-bytes', $result->bytes);
    }

    private function mockEventWithRelations(bool $walletEnabled = true): EventDomainObject
    {
        $organizer = m::mock(OrganizerDomainObject::class);
        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getWalletPassesEnabled')->andReturn($walletEnabled);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($settings);

        return $event;
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
