<?php

namespace HiEvents\Mail\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Attendee\GenerateAttendeeTicketPDFService;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Event\GenerateEventIcsService;
use HiEvents\Services\Domain\QrCode\QrCodeService;
use HiEvents\Services\Domain\Wallet\GoogleWalletPassService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use Throwable;

/**
 * @uses /backend/resources/views/emails/orders/attendee-ticket.blade.php
 */
class AttendeeTicketMail extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly AttendeeDomainObject     $attendee,
        private readonly EventDomainObject        $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject    $organizer,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
        private readonly bool                     $isReminder = false,
    )
    {
        parent::__construct();
        $this->renderedTemplate = $renderedTemplate;
    }

    public function envelope(): Envelope
    {
        if ($this->isReminder) {
            $subject = __('🎟️ Reminder: your ticket for :event', [
                'event' => Str::limit($this->event->getTitle(), 50),
            ]);
        } else {
            $subject = $this->renderedTemplate?->subject ?? __('🎟️ Your Ticket for :event', [
                'event' => Str::limit($this->event->getTitle(), 50)
            ]);
        }

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        // If no template is provided, use the default blade template
        return new Content(
            markdown: 'emails.orders.attendee-ticket',
            with: [
                'event' => $this->event,
                'attendee' => $this->attendee,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'order' => $this->order,
                'isReminder' => $this->isReminder,
                'qrCid' => 'attendee-qr.png',
                'qrFilename' => 'attendee-qr.png',
                'qrPng' => $this->generateQrPng(),
                'googleWalletUrl' => $this->generateGoogleWalletUrl(),
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $this->attendee->getShortId(),
                )
            ]
        );
    }

    /**
     * Build a "Save to Google Wallet" URL. Returns null when wallet passes
     * are disabled for this event, when Google Wallet isn't configured for
     * this install, or when signing fails — the blade conditionally hides
     * the button.
     */
    private function generateGoogleWalletUrl(): ?string
    {
        if (!$this->eventSettings->getWalletPassesEnabled()) {
            return null;
        }

        if (!config('wallet.google.issuer_id')) {
            return null;
        }

        try {
            return app(GoogleWalletPassService::class)->generateSaveLink(
                $this->attendee,
                $this->event,
                $this->organizer,
                $this->eventSettings,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function attachments(): array
    {
        $calendar = app(GenerateEventIcsService::class)->generate(
            $this->event,
            $this->eventSettings,
            $this->organizer,
            'event-' . $this->attendee->getId(),
        );

        return [
            Attachment::fromData(static fn() => $calendar, 'event.ics')
                ->withMime('text/calendar'),
            Attachment::fromData(
                fn () => app(GenerateAttendeeTicketPDFService::class)
                    ->generate($this->attendee, $this->resolveEventForPdf(), $this->order),
                'ticket.pdf'
            )->withMime('application/pdf'),
        ];
    }

    /**
     * Generate the inline QR PNG bytes for the blade `$message->embedData()` call.
     *
     * Returns an empty string on failure so the email send is never blocked by QR
     * rendering issues — the PDF attachment still carries a QR as a backup.
     */
    private function generateQrPng(): string
    {
        try {
            return app(QrCodeService::class)->generatePng($this->attendee->getPublicId() ?? (string) $this->attendee->getId());
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Ensure the event passed to the PDF service has organizer + eventSettings
     * available, since GenerateAttendeeTicketPDFService reads them off the event.
     */
    private function resolveEventForPdf(): EventDomainObject
    {
        if ($this->event->getOrganizer() === null) {
            $this->event->setOrganizer($this->organizer);
        }

        if ($this->event->getEventSettings() === null) {
            $this->event->setEventSettings($this->eventSettings);
        }

        return $this->event;
    }
}
