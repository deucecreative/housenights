<?php

namespace HiEvents\Mail\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single consolidated email to the purchaser containing every active attendee's
 * QR code inline plus a multi-ticket PDF attachment.
 *
 * @uses /backend/resources/views/emails/orders/order-tickets.blade.php
 */
class OrderTicketsMail extends BaseMail
{
    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly EventDomainObject        $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject    $organizer,
        private readonly bool                     $isReminder = false,
    )
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        if ($this->isReminder) {
            $subject = __('🎟️ Reminder: your tickets for :event', [
                'event' => Str::limit($this->event->getTitle(), 50),
            ]);
        } else {
            $subject = __('🎟️ Your tickets for :event', [
                'event' => Str::limit($this->event->getTitle(), 50),
            ]);
        }

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $activeAttendees = $this->getActiveAttendees();

        $qrPngs = [];
        $qrFilenames = [];
        $attendeeTicketUrls = [];

        foreach ($activeAttendees as $attendee) {
            $attendeeId = $attendee->getId();

            $qrPngs[$attendeeId] = $this->generateQrPng($attendee);
            $qrFilenames[$attendeeId] = 'attendee-' . $attendee->getShortId() . '.png';
            $attendeeTicketUrls[$attendeeId] = sprintf(
                Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                $this->event->getId(),
                $attendee->getShortId(),
            );
        }

        return new Content(
            markdown: 'emails.orders.order-tickets',
            with: [
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                'attendees' => $activeAttendees,
                'qrPngs' => $qrPngs,
                'qrFilenames' => $qrFilenames,
                'attendeeTicketUrls' => $attendeeTicketUrls,
                'isReminder' => $this->isReminder,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn() => app(GenerateOrderTicketsPDFService::class)->generate($this->order),
                'tickets.pdf',
            )->withMime('application/pdf'),
        ];
    }

    /**
     * Filter the order's attendees down to ACTIVE only.
     */
    private function getActiveAttendees(): Collection
    {
        $attendees = $this->order->getAttendees() ?? new Collection();

        return $attendees
            ->filter(fn(AttendeeDomainObject $attendee) => $attendee->getStatus() === AttendeeStatus::ACTIVE->name)
            ->values();
    }

    /**
     * Generate inline QR PNG bytes for embedData. Returns empty string on failure
     * so the email still sends — the PDF attachment carries a backup QR.
     */
    private function generateQrPng(AttendeeDomainObject $attendee): string
    {
        try {
            return app(QrCodeService::class)->generatePng(
                $attendee->getPublicId() ?? (string) $attendee->getId(),
            );
        } catch (Throwable) {
            return '';
        }
    }
}
