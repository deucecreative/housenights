<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Tests\TestCase;

class GenerateAttendeeTicketPDFServiceTest extends TestCase
{
    private GenerateAttendeeTicketPDFService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GenerateAttendeeTicketPDFService(new QrCodeService());
    }

    public function test_generates_pdf_bytes_for_an_attendee(): void
    {
        [$event, $attendee] = $this->buildFixtures();

        $pdf = $this->service->generate($attendee, $event);

        $this->assertNotEmpty($pdf, 'Generated PDF bytes should not be empty');
        $this->assertSame('%PDF-', substr($pdf, 0, 5), 'PDF should start with the %PDF- magic header');
        $this->assertGreaterThan(2000, strlen($pdf), 'PDF should have a non-trivial size');
    }

    /**
     * @return array{0: EventDomainObject, 1: AttendeeDomainObject}
     */
    private function buildFixtures(): array
    {
        $product = (new ProductDomainObject())
            ->setId(101)
            ->setTitle('General Admission');

        $organizer = (new OrganizerDomainObject())
            ->setId(11)
            ->setName('Test Organizer')
            ->setEmail('organizer@example.com');

        $eventSettings = (new EventSettingDomainObject())
            ->setId(22)
            ->setEventId(33)
            ->setLocationDetails([
                'venue_name' => 'The Venue',
                'address_line_1' => '1 Main Street',
                'city' => 'Sydney',
                'country' => 'AU',
            ]);

        $event = (new EventDomainObject())
            ->setId(33)
            ->setTitle('Test Event')
            ->setStartDate('2030-01-01 19:00:00')
            ->setTimezone('UTC');
        $event->setOrganizer($organizer);
        $event->setEventSettings($eventSettings);

        $attendee = (new AttendeeDomainObject())
            ->setId(501)
            ->setOrderId(701)
            ->setProductId(101)
            ->setEventId(33)
            ->setProductPriceId(201)
            ->setShortId('ATT-501')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setEmail('jane@example.com')
            ->setPublicId('public-501')
            ->setStatus(AttendeeStatus::ACTIVE->name);
        $attendee->setProduct($product);

        return [$event, $attendee];
    }
}
