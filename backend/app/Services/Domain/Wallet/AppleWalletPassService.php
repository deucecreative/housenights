<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet;

use Illuminate\Support\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\Url;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Spatie\LaravelMobilePass\Builders\Apple\EventTicketPassBuilder;
use Spatie\LaravelMobilePass\Enums\BarcodeType;
use Spatie\LaravelMobilePass\Enums\FieldType;
use Throwable;

/**
 * Generates a signed Apple Wallet (.pkpass) bundle for a ticket.
 *
 * Delegates the actual signing pipeline to spatie/laravel-mobile-pass
 * (which wraps the well-tested PKPass library). We just map our domain
 * objects to the builder's API and return the raw .pkpass bytes.
 *
 * Pass type / team / cert / cert password are read from our standard
 * APPLE_WALLET_* env vars via config/mobile-pass.php (which bridges them
 * to the library's expected keys).
 */
class AppleWalletPassService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Generate a signed .pkpass and return the raw bytes.
     *
     * Caller MUST eager-load:
     *   - $attendee->getProduct()
     *   - $attendee->getOrder() (for back-of-pass order number)
     */
    public function generatePass(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): string {
        $builder = $this->buildPass($attendee, $event, $organizer, $eventSettings);

        try {
            return $builder->generate();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Failed to generate Apple Wallet pass: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function buildPass(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): EventTicketPassBuilder {
        $fullName = trim($attendee->getFirstName() . ' ' . $attendee->getLastName());
        $productTitle = $attendee->getProduct()?->getTitle() ?? __('Ticket');
        $venue = $eventSettings->getAddressString() ?: (string)__('Online Event');
        $organizerName = $organizer->getName() ?: 'House Nights';
        $publicId = $attendee->getPublicId();

        $builder = EventTicketPassBuilder::make()
            ->setSerialNumber($publicId)
            ->setOrganizationName($organizerName)
            ->setDescription(trim($event->getTitle() . ' — ' . ($fullName ?: 'Attendee')))
            ->setBackgroundColor($this->deriveBackgroundColor($eventSettings))
            ->setForegroundColor('#ffffff')
            ->setLabelColor('#ffffff')
            ->setBarcode(BarcodeType::Qr, $publicId, $publicId)
            ->addField(
                key: 'event',
                value: $event->getTitle(),
                type: FieldType::Primary,
                label: (string)__('Event'),
            )
            ->addSecondaryField(
                key: 'name',
                value: $fullName ?: ($attendee->getEmail() ?: 'Attendee'),
                label: (string)__('Name'),
            )
            ->addAuxiliaryField(
                key: 'ticket',
                value: $productTitle,
                label: (string)__('Ticket'),
            )
            ->addAuxiliaryField(
                key: 'venue',
                value: $venue,
                label: (string)__('Venue'),
            );

        $startIso = $this->convertEventDate($event->getStartDate(), $event->getTimezone());
        if ($startIso !== null) {
            $builder->addSecondaryField(
                key: 'date',
                value: $startIso,
                label: (string)__('Date'),
            );
            $builder->setRelevantDate(Carbon::parse($startIso));
        }

        // Back of pass: order number, attendee email, support email.
        $orderPublicId = $attendee->getOrder()?->getPublicId();
        if ($orderPublicId) {
            $builder->addBackField(
                key: 'order',
                value: (string)$orderPublicId,
                label: (string)__('Order Number'),
            );
        }
        if ($attendee->getEmail()) {
            $builder->addBackField(
                key: 'attendee_email',
                value: (string)$attendee->getEmail(),
                label: (string)__('Attendee Email'),
            );
        }
        if ($eventSettings->getSupportEmail()) {
            $builder->addBackField(
                key: 'support_email',
                value: (string)$eventSettings->getSupportEmail(),
                label: (string)__('Support'),
            );
        }
        // Link back to the ticket page on the frontend. Guard on event ID being
        // set so tests with partial fixtures don't blow up here.
        $frontendUrl = rtrim((string)$this->config->get('app.frontend_url'), '/');
        $eventId = $this->safeEventId($event);
        if ($frontendUrl !== '' && $eventId !== null) {
            $ticketPath = (string)($this->config->get(Url::ATTENDEE_TICKET) ?: '/product/%d/%s');
            $ticketUrl = $frontendUrl . sprintf($ticketPath, $eventId, $attendee->getShortId());
            $builder->addBackField(
                key: 'view_ticket',
                value: $ticketUrl,
                label: (string)__('View Online'),
            );
        }

        $this->attachImages($builder);
        $this->attachLocation($builder, $eventSettings);

        return $builder;
    }

    private function attachImages(EventTicketPassBuilder $builder): void
    {
        $imagesDir = storage_path('app/wallet/apple/images');

        $iconBase = $imagesDir . '/icon.png';
        $icon2x = $imagesDir . '/icon@2x.png';
        $icon3x = $imagesDir . '/icon@3x.png';

        if (is_file($iconBase) && is_file($icon2x)) {
            $builder->setIconImage(
                $iconBase,
                $icon2x,
                is_file($icon3x) ? $icon3x : null,
            );
        }

        $logoBase = $imagesDir . '/logo.png';
        $logo2x = $imagesDir . '/logo@2x.png';
        $logo3x = $imagesDir . '/logo@3x.png';

        if (is_file($logoBase)) {
            $builder->setLogoImage(
                $logoBase,
                is_file($logo2x) ? $logo2x : null,
                is_file($logo3x) ? $logo3x : null,
            );
        }
    }

    private function attachLocation(EventTicketPassBuilder $builder, EventSettingDomainObject $eventSettings): void
    {
        $locationDetails = $eventSettings->getLocationDetails();
        if (!is_array($locationDetails)) {
            return;
        }

        $lat = $locationDetails['lat'] ?? $locationDetails['latitude'] ?? null;
        $lng = $locationDetails['lng'] ?? $locationDetails['longitude'] ?? null;

        if (is_numeric($lat) && is_numeric($lng)) {
            $builder->addLocation((float)$lat, (float)$lng);
        }
    }

    private function deriveBackgroundColor(EventSettingDomainObject $eventSettings): string
    {
        $design = method_exists($eventSettings, 'getTicketDesignSettings')
            ? $eventSettings->getTicketDesignSettings()
            : null;

        if (is_array($design)) {
            $accent = $design['accent_color'] ?? null;
            if (is_string($accent) && preg_match('/^#?([0-9a-fA-F]{6})$/', $accent, $matches)) {
                return '#' . strtolower($matches[1]);
            }
        }

        // House Nights brand purple — used when the event hasn't set a ticket
        // design accent color.
        return '#57398e';
    }

    /**
     * Return the event's id if it's been populated, or null if the property
     * hasn't been initialized (happens in tests with minimal fixtures).
     */
    private function safeEventId(EventDomainObject $event): ?int
    {
        try {
            return $event->getId();
        } catch (\Error) {
            return null;
        }
    }

    private function convertEventDate(?string $date, ?string $timezone): ?string
    {
        if (!$date) {
            return null;
        }
        try {
            // Convert from UTC storage to event-local tz, emit ISO 8601 with Z
            // for UTC strictness.
            return Carbon::parse(DateHelper::convertFromUTC($date, $timezone ?: 'UTC'))
                ->utc()
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
