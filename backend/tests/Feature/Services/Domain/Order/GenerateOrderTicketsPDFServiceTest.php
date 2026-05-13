<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GenerateOrderTicketsPDFServiceTest extends TestCase
{
    private GenerateOrderTicketsPDFService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GenerateOrderTicketsPDFService(new QrCodeService());
    }

    public function test_generates_pdf_with_multiple_attendees(): void
    {
        $order = $this->buildOrder([
            ['Alice', 'Active', AttendeeStatus::ACTIVE->name],
            ['Bob', 'Active', AttendeeStatus::ACTIVE->name],
        ]);

        $pdf = $this->service->generate($order);

        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        $this->assertGreaterThan(5000, strlen($pdf), 'A multi-attendee PDF should have a non-trivial size');
    }

    public function test_skips_non_active_attendees(): void
    {
        $orderTwoActive = $this->buildOrder([
            ['Alice', 'Active', AttendeeStatus::ACTIVE->name],
            ['Bob', 'Active', AttendeeStatus::ACTIVE->name],
        ]);
        $orderOneActive = $this->buildOrder([
            ['Alice', 'Active', AttendeeStatus::ACTIVE->name],
            ['Carla', 'Cancelled', AttendeeStatus::CANCELLED->name],
        ]);

        $pdfTwoActive = $this->service->generate($orderTwoActive);
        $pdfOneActive = $this->service->generate($orderOneActive);

        $this->assertSame('%PDF-', substr($pdfOneActive, 0, 5));
        // The single-active PDF should be meaningfully smaller than the two-active one,
        // confirming the cancelled attendee was filtered out (no extra page rendered).
        $this->assertLessThan(
            strlen($pdfTwoActive),
            strlen($pdfOneActive),
            'PDF with one active attendee should be smaller than PDF with two active attendees',
        );
    }

    public function test_throws_when_no_active_attendees(): void
    {
        $order = $this->buildOrder([
            ['Carla', 'Cancelled', AttendeeStatus::CANCELLED->name],
        ]);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('No active attendees to generate tickets for');

        $this->service->generate($order);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $attendees [firstName, lastName, status]
     */
    private function buildOrder(array $attendees): OrderDomainObject
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

        $attendeeCollection = new Collection();
        $idCounter = 500;
        foreach ($attendees as [$firstName, $lastName, $status]) {
            $idCounter++;
            $attendee = (new AttendeeDomainObject())
                ->setId($idCounter)
                ->setOrderId(701)
                ->setProductId(101)
                ->setEventId(33)
                ->setProductPriceId(201)
                ->setShortId('ATT-' . $idCounter)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setEmail(strtolower($firstName) . '@example.com')
                ->setPublicId('public-' . $idCounter)
                ->setStatus($status);
            $attendee->setProduct($product);
            $attendeeCollection->push($attendee);
        }

        $order = (new OrderDomainObject())
            ->setId(701)
            ->setEventId(33)
            ->setShortId('ORD-701')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setEmail('jane@example.com');
        $order->setEvent($event);
        $order->setAttendees($attendeeCollection);

        return $order;
    }
}
