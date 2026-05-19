<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\Order\GenerateOrderTicketsPDFService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class GenerateTestTicketPdfCommand extends Command
{
    protected $signature = 'tickets:test-pdf {--output=/tmp/housenights-ticket-test.pdf}';

    protected $description = 'Render the ticket PDF with sample data for local layout/font preview.';

    public function handle(GenerateOrderTicketsPDFService $service): int
    {
        $product = new ProductDomainObject();
        $product->setTitle('General Admission');

        $attendee = new AttendeeDomainObject();
        $attendee->setId(1);
        $attendee->setPublicId('att-pub-local-test');
        $attendee->setShortId('A-3G0RE4V');
        $attendee->setFirstName('Matt');
        $attendee->setLastName('Sims');
        $attendee->setEmail('matt@deucecreative.co.uk');
        $attendee->setStatus(AttendeeStatus::ACTIVE->name);
        $attendee->setProduct($product);

        $organizer = new OrganizerDomainObject();
        $organizer->setName('Deuce');
        $organizer->setEmail('hello@housenights.co');

        $settings = new EventSettingDomainObject();
        $settings->setLocationDetails([
            'venue_name' => 'Phonox',
            'address_line_1' => '418 Brixton Rd',
            'city' => 'London',
            'country' => 'GB',
        ]);
        $settings->setSupportEmail('support@housenights.co');

        $event = new EventDomainObject();
        $event->setTitle('Test Fest');
        $event->setStartDate(now()->addMonth()->setTime(12, 0, 0)->toDateTimeString());
        $event->setTimezone('Europe/London');
        $event->setOrganizer($organizer);
        $event->setEventSettings($settings);

        $order = new OrderDomainObject();
        $order->setPublicId('HN-LOCAL-TEST');
        $order->setAttendees(new Collection([$attendee]));

        $output = (string)$this->option('output');

        try {
            $bytes = $service->generate($order, $event);
        } catch (Throwable $e) {
            $this->error('PDF generation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (file_put_contents($output, $bytes) === false) {
            $this->error("Could not write PDF to {$output}");
            return self::FAILURE;
        }

        $this->info(sprintf('PDF written: %s (%d bytes)', $output, strlen($bytes)));
        $this->line('Open it with: open ' . escapeshellarg($output));

        return self::SUCCESS;
    }
}
