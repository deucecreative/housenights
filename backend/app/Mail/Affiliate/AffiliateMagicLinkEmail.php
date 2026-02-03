<?php

namespace HiEvents\Mail\Affiliate;

use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/affiliate/magic-link.blade.php
 */
class AffiliateMagicLinkEmail extends BaseMail
{
    public function __construct(
        private readonly string $affiliateName,
        private readonly string $eventTitle,
        private readonly string $token,
        private readonly string $affiliateTerm = 'Affiliate',
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :term Portal Access - :event', ['term' => $this->affiliateTerm, 'event' => $this->eventTitle]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.affiliate.magic-link',
            with: [
                'affiliateName' => $this->affiliateName,
                'eventTitle' => $this->eventTitle,
                'affiliateTerm' => $this->affiliateTerm,
                'portalUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::AFFILIATE_PORTAL),
                    $this->token,
                ),
            ]
        );
    }
}
