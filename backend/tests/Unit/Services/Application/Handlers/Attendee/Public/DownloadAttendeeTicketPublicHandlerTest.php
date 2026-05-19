<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Attendee\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\Public\DownloadAttendeeTicketPublicHandler;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class DownloadAttendeeTicketPublicHandlerTest extends TestCase
{
    private AttendeeRepositoryInterface $attendeeRepository;
    private EventRepositoryInterface $eventRepository;
    private GenerateAttendeeTicketPDFService $pdfService;
    private DownloadAttendeeTicketPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->pdfService = m::mock(GenerateAttendeeTicketPDFService::class);

        $this->handler = new DownloadAttendeeTicketPublicHandler(
            $this->attendeeRepository,
            $this->eventRepository,
            $this->pdfService,
        );
    }

    public function testReturnsPdfBytesAndFilenameForActiveAttendee(): void
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);
        $attendee->shouldReceive('getPublicId')->andReturn('pub-att-1');

        $event = $this->mockEventWithRelations();

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => 42,
                'short_id' => 'ATT-XYZ',
            ])
            ->andReturn($attendee);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirstWhere')->once()->with(['id' => 42])->andReturn($event);

        $this->pdfService->shouldReceive('generate')
            ->once()
            ->with($attendee, $event)
            ->andReturn('%PDF-...bytes...');

        $result = $this->handler->handle(eventId: 42, attendeeShortId: 'ATT-XYZ');

        $this->assertSame('%PDF-...bytes...', $result->bytes);
        $this->assertSame('ticket-pub-att-1.pdf', $result->filename);
    }

    public function testThrowsWhenAttendeeNotFound(): void
    {
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 42, attendeeShortId: 'NONE');
    }

    public function testThrowsWhenAttendeeNotActive(): void
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::CANCELLED->name);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);

        $this->pdfService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 42, attendeeShortId: 'ATT-XYZ');
    }

    public function testCrossEventShortIdRejectedByRepositoryQuery(): void
    {
        // Regression: an attendee short_id from event A must not be redeemable
        // against event B. The repository query keys on (event_id, short_id).
        // The mock returns null only when both match — simulating a real DB.
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')
            ->withArgs(function ($conditions) {
                return $conditions['event_id'] === 99 && $conditions['short_id'] === 'ATT-FROM-EVENT-1';
            })
            ->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(eventId: 99, attendeeShortId: 'ATT-FROM-EVENT-1');
    }

    public function testThrowsWhenEventNotFound(): void
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
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
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);
        $attendee->shouldReceive('getPublicId')->andReturn('pub-1');

        $event = $this->mockEventWithRelations(walletEnabled: $walletEnabled);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($attendee);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($event);

        $this->pdfService->shouldReceive('generate')->once()->andReturn('%PDF-bytes');

        $result = $this->handler->handle(eventId: 1, attendeeShortId: 'A');

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
