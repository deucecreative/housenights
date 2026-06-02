<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Services\Domain\Wallet\AppleWalletPassService;
use Illuminate\Console\Command;
use Throwable;

class GenerateTestApplePass extends Command
{
    protected $signature = 'wallet:test-apple-pass {--output=/tmp/housenights-test.pkpass}';

    protected $description = 'Generate a local Apple Wallet .pkpass with sample data for layout preview.';

    public function handle(AppleWalletPassService $service): int
    {
        $attendee = new AttendeeDomainObject();
        $attendee->setPublicId('att-pub-local-' . uniqid());
        $attendee->setShortId('att-short-local');
        $attendee->setFirstName('Matt');
        $attendee->setLastName('Sims');
        $attendee->setEmail('matt@flowguys.com');

        $product = new ProductDomainObject();
        $product->setTitle('General Admission');
        $attendee->setProduct($product);

        $order = new OrderDomainObject();
        $order->setPublicId('HN-LOCAL-' . strtoupper(uniqid()));
        $attendee->setOrder($order);

        $event = new EventDomainObject();
        $event->setTitle('Grofomo — Local Test');
        $event->setStartDate(now()->addDays(7)->setTime(20, 0, 0)->toDateTimeString());
        $event->setTimezone('Europe/London');

        $organizer = new OrganizerDomainObject();
        $organizer->setName('Grofomo');
        $organizer->setEmail('hello@grofomo.com');

        $settings = new EventSettingDomainObject();
        $settings->setLocationDetails([
            'venue_name' => 'Phonox',
            'address_line_1' => '418 Brixton Rd',
            'city' => 'London',
            'country' => 'GB',
        ]);
        $settings->setSupportEmail('support@grofomo.com');

        $output = (string)$this->option('output');

        try {
            $bytes = $service->generatePass($attendee, $event, $organizer, $settings);
        } catch (Throwable $e) {
            $this->error('Pass generation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (file_put_contents($output, $bytes) === false) {
            $this->error("Could not write pass to {$output}");
            return self::FAILURE;
        }

        $this->info(sprintf('Pass written: %s (%d bytes)', $output, strlen($bytes)));
        $this->line('Open it with: open ' . escapeshellarg($output));

        return self::SUCCESS;
    }
}
