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

    public function test_attaches_event_ics(): void
    {
        $mail = $this->buildMail();

        $attachments = $mail->attachments();

        $icsAttachment = $this->findAttachmentByName($attachments, 'event.ics');
        $this->assertNotNull($icsAttachment, 'Expected an event.ics attachment');
        $this->assertSame('text/calendar', $this->getAttachmentMime($icsAttachment));

        $bytes = $this->resolveAttachmentBytes($icsAttachment);
        $this->assertStringStartsWith('BEGIN:VCALENDAR', $bytes, 'ICS should start with BEGIN:VCALENDAR');
    }

    public function test_mentions_all_attendees_and_other_attendee_copies(): void
    {
        // Two attendees on different emails (jane is also the purchaser), and the
        // per-attendee emails are being sent in this flow.
        $mail = $this->buildMail(attendeesAlsoEmailed: true);

        $rendered = $mail->render();

        $this->assertStringContainsString('everyone in your order, including your own', $rendered);
        $this->assertStringContainsString('other attendees has also been emailed', $rendered);
    }

    public function test_hides_other_attendee_copy_line_for_solo_order(): void
    {
        $mail = $this->buildMail(soloPurchaserAttendee: true, attendeesAlsoEmailed: true);

        $rendered = $mail->render();

        $this->assertStringContainsString('everyone in your order', $rendered);
        $this->assertStringNotContainsString('other attendees has also been emailed', $rendered);
    }

    public function test_hides_other_attendee_copy_line_when_attendees_not_emailed(): void
    {
        // Resend-to-purchaser shape: multiple attendees on different emails, but
        // the per-attendee emails are NOT dispatched in this send.
        $mail = $this->buildMail(attendeesAlsoEmailed: false);

        $rendered = $mail->render();

        $this->assertStringNotContainsString('other attendees has also been emailed', $rendered);
    }

    public function test_omits_including_your_own_when_purchaser_not_an_attendee(): void
    {
        // Gift / buy-for-others: the purchaser holds no ticket of their own.
        $mail = $this->buildMail(attendeesAlsoEmailed: true, purchaserIsAttendee: false);

        $rendered = $mail->render();

        $this->assertStringContainsString('everyone in your order', $rendered);
        $this->assertStringNotContainsString('including your own', $rendered);
        // Other attendees were still emailed their individual tickets.
        $this->assertStringContainsString('other attendees has also been emailed', $rendered);
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

    public function test_hides_wallet_buttons_when_disabled(): void
    {
        $mail = $this->buildMail(walletPassesEnabled: false);

        $rendered = $mail->render();

        $this->assertStringNotContainsString('Add to Apple Wallet', $rendered);
        $this->assertStringNotContainsString('Add to Google Wallet', $rendered);
        $this->assertStringNotContainsString('/apple-pass', $rendered);
    }

    /**
     * Regression for production crash where the queue worker rehydrated the
     * mailable, called attachments(), and the PDF service blew up because it
     * tried to read $order->getEvent() on an order whose event relation was
     * never eager-loaded. Mimics ResendOrderConfirmationAction's call shape:
     * order has NO event linked, event is passed as a separate constructor arg.
     */
    public function test_survives_queue_serialize_unserialize_with_no_event_on_order(): void
    {
        [$order, $event, $eventSettings, $organizer] = $this->buildFixtures(includeCancelledAttendee: false);
        // Production shape: order is loaded without the event relation.
        $order->setEvent(null);

        $mail = new OrderTicketsMail(
            order: $order,
            event: $event,
            eventSettings: $eventSettings,
            organizer: $organizer,
        );

        // Simulate exactly what the queue worker does: serialize the mailable,
        // then rehydrate it before invoking attachments().
        /** @var OrderTicketsMail $rehydrated */
        $rehydrated = unserialize(serialize($mail));

        $attachments = $rehydrated->attachments();
        $pdfAttachment = $this->findAttachmentByName($attachments, 'tickets.pdf');
        $this->assertNotNull($pdfAttachment, 'Expected a tickets.pdf attachment');

        $bytes = $this->resolveAttachmentBytes($pdfAttachment);
        $this->assertNotEmpty($bytes, 'PDF bytes should not be empty after queue rehydrate');
        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'PDF should start with %PDF- header');
    }

    private function buildMail(
        bool $isReminder = false,
        bool $includeCancelledAttendee = false,
        bool $walletPassesEnabled = false,
        bool $soloPurchaserAttendee = false,
        bool $attendeesAlsoEmailed = false,
        bool $purchaserIsAttendee = true,
    ): OrderTicketsMail {
        [$order, $event, $eventSettings, $organizer] = $this->buildFixtures($includeCancelledAttendee, $walletPassesEnabled, $soloPurchaserAttendee, $purchaserIsAttendee);

        return new OrderTicketsMail(
            order: $order,
            event: $event,
            eventSettings: $eventSettings,
            organizer: $organizer,
            isReminder: $isReminder,
            attendeesAlsoEmailed: $attendeesAlsoEmailed,
        );
    }

    /**
     * @return array{0: OrderDomainObject, 1: EventDomainObject, 2: EventSettingDomainObject, 3: OrganizerDomainObject}
     */
    private function buildFixtures(bool $includeCancelledAttendee, bool $walletPassesEnabled = false, bool $soloPurchaserAttendee = false, bool $purchaserIsAttendee = true): array
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
            ->setWalletPassesEnabled($walletPassesEnabled)
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

        // Solo order: only the purchaser (jane) is an attendee, so there are no
        // "other attendees" and the per-attendee-copy line must not render.
        $attendees = $soloPurchaserAttendee
            ? new Collection([$activeOne])
            : new Collection([$activeOne, $activeTwo]);

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

        // When the purchaser is not an attendee (gift / buy-for-others), the
        // order email belongs to no one in the attendees list.
        $order = (new OrderDomainObject())
            ->setId(701)
            ->setEventId(33)
            ->setShortId('ORD-701')
            ->setEmail($purchaserIsAttendee ? 'jane@example.com' : 'buyer@example.com')
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
