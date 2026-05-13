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
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use Illuminate\Mail\Mailables\Attachment;
use Tests\TestCase;

class AttendeeTicketMailTest extends TestCase
{
    public function test_includes_pdf_attachment(): void
    {
        $mail = $this->buildMail();

        $attachments = $mail->attachments();

        $pdfAttachment = $this->findAttachmentByName($attachments, 'ticket.pdf');
        $this->assertNotNull($pdfAttachment, 'Expected a ticket.pdf attachment');

        $bytes = $this->resolveAttachmentBytes($pdfAttachment);
        $this->assertNotEmpty($bytes, 'PDF attachment bytes should not be empty');
        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'PDF should start with %PDF- header');

        $mime = $this->getAttachmentMime($pdfAttachment);
        $this->assertSame('application/pdf', $mime);
    }

    public function test_includes_event_ics_attachment(): void
    {
        $mail = $this->buildMail();

        $attachments = $mail->attachments();
        $ics = $this->findAttachmentByName($attachments, 'event.ics');

        $this->assertNotNull($ics, 'Expected event.ics attachment to remain present');
        $this->assertSame('text/calendar', $this->getAttachmentMime($ics));
    }

    public function test_subject_uses_reminder_copy_when_flag_set(): void
    {
        $mail = $this->buildMail(isReminder: true);

        $subject = $mail->envelope()->subject;

        $this->assertStringContainsString('Reminder', $subject);
    }

    public function test_default_subject_when_not_reminder(): void
    {
        $mail = $this->buildMail(isReminder: false);

        $subject = $mail->envelope()->subject;

        $this->assertStringNotContainsString('Reminder', $subject);
        $this->assertTrue(
            str_contains($subject, '🎟️') || str_contains($subject, 'Ticket'),
            'Default subject should contain the ticket glyph or the word "Ticket"; got: ' . $subject
        );
    }

    private function buildMail(bool $isReminder = false): AttendeeTicketMail
    {
        [$order, $attendee, $event, $eventSettings, $organizer] = $this->buildFixtures();

        return new AttendeeTicketMail(
            order: $order,
            attendee: $attendee,
            event: $event,
            eventSettings: $eventSettings,
            organizer: $organizer,
            renderedTemplate: null,
            isReminder: $isReminder,
        );
    }

    /**
     * @return array{0: OrderDomainObject, 1: AttendeeDomainObject, 2: EventDomainObject, 3: EventSettingDomainObject, 4: OrganizerDomainObject}
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

        $order = (new OrderDomainObject())
            ->setId(701)
            ->setEventId(33)
            ->setShortId('ORD-701')
            ->setEmail('jane@example.com')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setStatus(OrderStatus::COMPLETED->name);

        return [$order, $attendee, $event, $eventSettings, $organizer];
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
