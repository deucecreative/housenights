<?php

declare(strict_types=1);

namespace HiEvents\Mail\Affiliate;

use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AffiliateWelcomeEmail extends BaseMail
{
    public function __construct(
        public readonly string $affiliateName,
        public readonly string $eventTitle,
        public readonly string $affiliateCode,
        public readonly string $affiliateUrl,
        public readonly string $loginUrl,
        public readonly string $affiliateTerm = 'Affiliate',
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Welcome to the :term program for :event', ['term' => $this->affiliateTerm, 'event' => $this->eventTitle]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.affiliate.welcome',
            with: [
                'affiliateName' => $this->affiliateName,
                'eventTitle' => $this->eventTitle,
                'affiliateCode' => $this->affiliateCode,
                'affiliateUrl' => $this->affiliateUrl,
                'loginUrl' => $this->loginUrl,
                'affiliateTerm' => $this->affiliateTerm,
            ],
        );
    }
}
