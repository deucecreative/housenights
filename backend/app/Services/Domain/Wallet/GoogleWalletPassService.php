<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Spatie\LaravelMobilePass\Support\Google\GoogleJwtSigner;
use Throwable;

/**
 * Generates "Save to Google Wallet" links for attendee tickets.
 *
 * We build an EventTicketClass + EventTicketObject inline (no pre-created
 * classes via the Wallet API needed), then hand the payload to
 * spatie/laravel-mobile-pass's GoogleJwtSigner which signs RS256 using
 * the service account credentials loaded from config/mobile-pass.php.
 *
 * Credentials are bridged to the library via config/mobile-pass.php from
 * our standard GOOGLE_WALLET_* env vars.
 */
class GoogleWalletPassService
{
    private const SAVE_URL_PREFIX = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GoogleJwtSigner $jwtSigner,
    ) {
    }

    /**
     * Build a signed "Save to Google Wallet" URL for the given attendee.
     */
    public function generateSaveLink(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): string {
        $issuerId = (string)$this->config->get('mobile-pass.google.issuer_id');
        if ($issuerId === '') {
            throw new RuntimeException('Google Wallet issuer ID is not configured');
        }

        $classId = $this->buildClassId($issuerId, $event);
        $objectId = $this->buildObjectId($issuerId, $attendee, $event);

        $payload = [
            'eventTicketClasses' => [
                $this->buildEventTicketClass($classId, $event, $organizer, $eventSettings),
            ],
            'eventTicketObjects' => [
                $this->buildEventTicketObject($objectId, $classId, $attendee),
            ],
        ];

        try {
            $jwt = $this->jwtSigner->signSaveUrlJwt($payload);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Failed to sign Google Wallet save URL: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return self::SAVE_URL_PREFIX . $jwt;
    }

    /**
     * Class IDs identify the *event* (one class per event) and must follow
     * the form `{issuer_id}.{suffix}` — the dot is mandatory.
     */
    private function buildClassId(string $issuerId, EventDomainObject $event): string
    {
        return $issuerId . '.event-' . $event->getId();
    }

    /**
     * Object IDs identify a single saved pass and must be globally unique
     * across the issuer. Including the event ID guards against an attendee
     * holding tickets to multiple events.
     */
    private function buildObjectId(string $issuerId, AttendeeDomainObject $attendee, EventDomainObject $event): string
    {
        return $issuerId . '.attendee-' . $attendee->getId() . '-event-' . $event->getId();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEventTicketClass(
        string $classId,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): array {
        $class = [
            'id' => $classId,
            'issuerName' => $organizer->getName() ?: 'Event Organizer',
            'eventName' => [
                'defaultValue' => [
                    'language' => 'en-US',
                    'value' => $event->getTitle(),
                ],
            ],
            'eventId' => 'evt-' . $event->getId(),
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => $this->deriveHexBackgroundColor($eventSettings),
        ];

        $logoUri = $this->resolveLogoUri();
        if ($logoUri !== null) {
            $class['logo'] = [
                'sourceUri' => ['uri' => $logoUri],
                'contentDescription' => [
                    'defaultValue' => [
                        'language' => 'en-US',
                        'value' => $organizer->getName() ?: 'Organizer logo',
                    ],
                ],
            ];
        }

        $startIso = $this->safeIso($event->getStartDate(), $event->getTimezone());
        $endIso = $this->safeIso($event->getEndDate(), $event->getTimezone());

        if ($startIso) {
            $dateTime = ['start' => $startIso];
            if ($endIso) {
                $dateTime['end'] = $endIso;
            }
            $class['dateTime'] = $dateTime;
        }

        $venueName = $organizer->getName() ?: 'Venue';
        $venueAddress = $eventSettings->getAddressString() ?: 'TBD';
        $class['venue'] = [
            'name' => [
                'defaultValue' => [
                    'language' => 'en-US',
                    'value' => $venueName,
                ],
            ],
            'address' => [
                'defaultValue' => [
                    'language' => 'en-US',
                    'value' => $venueAddress,
                ],
            ],
        ];

        return $class;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEventTicketObject(
        string $objectId,
        string $classId,
        AttendeeDomainObject $attendee,
    ): array {
        $publicId = (string)$attendee->getPublicId();
        $shortId = (string)$attendee->getShortId();
        $fullName = trim($attendee->getFirstName() . ' ' . $attendee->getLastName());

        return [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $publicId,
                'alternateText' => $shortId,
            ],
            'ticketHolderName' => $fullName !== '' ? $fullName : ($attendee->getEmail() ?: 'Attendee'),
            'ticketNumber' => $shortId,
        ];
    }

    /**
     * Google expects a `#RRGGBB` hex string (no `rgb()` form).
     */
    private function deriveHexBackgroundColor(EventSettingDomainObject $eventSettings): string
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

        // Fall back to the configured brand color (Grofomo Carbon by default).
        return config('mobile-pass.brand_color', '#080808');
    }

    /**
     * Resolve the logo URL Google should fetch when rendering the pass.
     * Falls back to a known asset under APP_FRONTEND_URL.
     */
    private function resolveLogoUri(): ?string
    {
        $configured = (string)$this->config->get('wallet.google.logo_uri');
        if ($configured !== '') {
            return $configured;
        }

        $frontendUrl = rtrim((string)$this->config->get('app.frontend_url'), '/');
        if ($frontendUrl === '') {
            return null;
        }

        return $frontendUrl . '/wallet/google-wallet-logo.png';
    }

    private function safeIso(?string $date, ?string $timezone): ?string
    {
        if (!$date) {
            return null;
        }
        try {
            return Carbon::parse($date, $timezone ?: 'UTC')->format('Y-m-d\TH:i:sP');
        } catch (Throwable) {
            return null;
        }
    }
}
