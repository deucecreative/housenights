<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Mail\Order\OrderTicketsMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderTicketsMailTest extends TestCase
{
    public function test_attaches_tickets_pdf(): void
    {
        $mail = $this->buildMail();

        $attachments = $mail->attachments();

        $pdfAttachment = $this->findAttachmentByName($attachments, 'tickets.pdf');
        $this->assertNotNull($pdfAttachment, 'Expected a tickets.pdf attachment');

        $bytes = $this->resolveAttachmentBytes($pdfAttachment);
        $this->assertNotEmpty($bytes, 'PDF attachment bytes should not be empty');
        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'PDF should start with %PDF- header');
        $this->assertSame('application/pdf', $this->getAttachmentMime($pdfAttachment));
    }

    public function test_embeds_qr_per_attendee(): void
    {
        $mail = $this->buildMail();

        $rendered = $mail->render();

        // During render(), Laravel's embedData inlines as `data:image/png;base64,...`.
        // With 2 active attendees we should see at least 2 base64-encoded PNG QR images
        // (the header logo is a regular https:// img, not a data URI, so it's excluded).
        preg_match_all('/data:image\/png;base64,iVBORw0KGgo[A-Za-z0-9+\/=]+/', $rendered, $matches);
        $this->assertGreaterThanOrEqual(2, count($matches[0]), 'Expected at least 2 inline PNG QR codes (one per active attendee), got ' . count($matches[0]));
    }

    public function test_reminder_subject(): void
    {
        $mail = $this->buildMail(isReminder: true);

        $this->assertStringContainsString('Reminder', $mail->envelope()->subject);
    }

    public function test_filters_to_active_attendees(): void
    {
        $mail = $this->buildMail(includeCancelledAttendee: true);

        $rendered = $mail->render();

        $this->assertStringContainsString('Jane', $rendered, 'Active attendee should appear');
        $this->assertStringNotContainsString('Cancelled', $rendered, 'Cancelled attendee should not appear');
    }

    private function buildMail(
        bool $isReminder = false,
        bool $includeCancelledAttendee = false,
    ): OrderTicketsMail {
        [$order, $event, $eventSettings, $organizer] = $this->buildFixtures($includeCancelledAttendee);

        return new OrderTicketsMail(
            order: $order,
            event: $event,
            eventSettings: $eventSettings,
            organizer: $organizer,
            isReminder: $isReminder,
        );
    }

    /**
     * @return array{0: OrderDomainObject, 1: EventDomainObject, 2: EventSettingDomainObject, 3: OrganizerDomainObject}
     */
    private function buildFixtures(bool $includeCancelledAttendee): array
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
            ->setSupportEmail('support@example.com')
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

        $activeOne = (new AttendeeDomainObject())
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
        $activeOne->setProduct($product);

        $activeTwo = (new AttendeeDomainObject())
            ->setId(502)
            ->setOrderId(701)
            ->setProductId(101)
            ->setEventId(33)
            ->setProductPriceId(201)
            ->setShortId('ATT-502')
            ->setFirstName('John')
            ->setLastName('Smith')
            ->setEmail('john@example.com')
            ->setPublicId('public-502')
            ->setStatus(AttendeeStatus::ACTIVE->name);
        $activeTwo->setProduct($product);

        $attendees = new Collection([$activeOne, $activeTwo]);

        if ($includeCancelledAttendee) {
            $cancelled = (new AttendeeDomainObject())
                ->setId(503)
                ->setOrderId(701)
                ->setProductId(101)
                ->setEventId(33)
                ->setProductPriceId(201)
                ->setShortId('ATT-503')
                ->setFirstName('Cancelled')
                ->setLastName('Person')
                ->setEmail('cancelled@example.com')
                ->setPublicId('public-503')
                ->setStatus(AttendeeStatus::CANCELLED->name);
            $cancelled->setProduct($product);
            $attendees->push($cancelled);
        }

        $order = (new OrderDomainObject())
            ->setId(701)
            ->setEventId(33)
            ->setShortId('ORD-701')
            ->setEmail('jane@example.com')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setStatus(OrderStatus::COMPLETED->name);
        $order->setAttendees($attendees);
        $order->setEvent($event);

        return [$order, $event, $eventSettings, $organizer];
    }

    /**
     * @param Attachment[] $attachments
     */
    private function findAttachmentByName(array $attachments, string $name): ?Attachment
    {
        foreach ($attachments as $attachment) {
            if ($this->getAttachmentName($attachment) === $name) {
                return $attachment;
            }
        }

        return null;
    }

    private function getAttachmentName(Attachment $attachment): ?string
    {
        return $attachment->as;
    }

    private function getAttachmentMime(Attachment $attachment): ?string
    {
        return $attachment->mime;
    }

    private function resolveAttachmentBytes(Attachment $attachment): string
    {
        $bytes = '';
        $attachment->attachWith(
            static function ($path) {
                return null;
            },
            static function (\Closure $data) use (&$bytes) {
                $bytes = (string) $data();
                return null;
            },
        );

        return $bytes;
    }
}
