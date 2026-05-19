<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Attendee\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\Public\DownloadAttendeeTicketPublicHandler;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class DownloadAttendeeTicketPublicHandlerTest extends TestCase
{
    private AttendeeRepositoryInterface $attendeeRepository;
    private ProductRepositoryInterface $productRepository;
    private OrderRepositoryInterface $orderRepository;
    private EventRepositoryInterface $eventRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private OrganizerRepositoryInterface $organizerRepository;
    private GenerateAttendeeTicketPDFService $pdfService;
    private DownloadAttendeeTicketPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->productRepository = m::mock(ProductRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $this->pdfService = m::mock(GenerateAttendeeTicketPDFService::class);

        $this->handler = new DownloadAttendeeTicketPublicHandler(
            $this->attendeeRepository,
            $this->productRepository,
            $this->orderRepository,
            $this->eventRepository,
            $this->eventSettingsRepository,
            $this->organizerRepository,
            $this->pdfService,
        );
    }

    public function testReturnsPdfBytesAndFilenameForActiveAttendee(): void
    {
        $attendee = $this->mockActiveAttendee(productId: 7, orderId: 11);
        $product = m::mock(ProductDomainObject::class);
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn(99);
        $event->shouldReceive('setEventSettings')->once();
        $event->shouldReceive('setOrganizer')->once();
        $settings = m::mock(EventSettingDomainObject::class);
        $organizer = m::mock(OrganizerDomainObject::class);
        $order = m::mock(OrderDomainObject::class);

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 42, 'short_id' => 'ATT-XYZ'])
            ->andReturn($attendee);
        $attendee->shouldReceive('setProduct')->once()->with($product);

        $this->productRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 7])
            ->andReturn($product);

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

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 11])
            ->andReturn($order);

        $this->pdfService
            ->shouldReceive('generate')
            ->once()
            ->with($attendee, $event, $order)
            ->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 42, attendeeShortId: 'ATT-XYZ');

        $this->assertSame('%PDF-bytes', $result->bytes);
        $this->assertSame('ticket-pub-att-1.pdf', $result->filename);
    }

    public function testThrowsWhenAttendeeNotFound(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, attendeeShortId: 'NONE');
    }

    public function testThrowsWhenAttendeeNotActive(): void
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::CANCELLED->name);

        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);
        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, attendeeShortId: 'ATT-XYZ');
    }

    public function testCrossEventShortIdRejectedByRepositoryQuery(): void
    {
        // Repository query keys on (event_id, short_id) so a short_id from
        // a different event never matches.
        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->withArgs(fn($conditions) =>
                $conditions['event_id'] === 99 && $conditions['short_id'] === 'ATT-FROM-EVENT-1'
            )
            ->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 99, attendeeShortId: 'ATT-FROM-EVENT-1');
    }

    public function testThrowsWhenEventNotFound(): void
    {
        $attendee = $this->mockActiveAttendee(productId: 7, orderId: 11);

        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);
        $attendee->shouldReceive('setProduct')->withAnyArgs();
        $this->productRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle(eventId: 42, attendeeShortId: 'ATT-XYZ');
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
        $attendee = $this->mockActiveAttendee(productId: 7, orderId: 11);
        $product = m::mock(ProductDomainObject::class);
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizerId')->andReturn(99);
        $event->shouldReceive('setEventSettings')->once();
        $event->shouldReceive('setOrganizer')->once();
        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getWalletPassesEnabled')->andReturn($walletEnabled);
        $organizer = m::mock(OrganizerDomainObject::class);

        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);
        $attendee->shouldReceive('setProduct')->with($product);
        $this->productRepository->shouldReceive('findFirstWhere')->andReturn($product);
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($event);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($settings);
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn($organizer);
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldReceive('generate')->once()->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 1, attendeeShortId: 'A');

        $this->assertSame('%PDF-bytes', $result->bytes);
    }

    private function mockActiveAttendee(int $productId, int $orderId): AttendeeDomainObject
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);
        $attendee->shouldReceive('getPublicId')->andReturn('pub-att-1');
        $attendee->shouldReceive('getProductId')->andReturn($productId);
        $attendee->shouldReceive('getOrderId')->andReturn($orderId);

        return $attendee;
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
