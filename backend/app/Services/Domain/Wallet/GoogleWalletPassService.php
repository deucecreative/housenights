<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Throwable;

/**
 * Generates "Save to Google Wallet" links for attendee tickets.
 *
 * Unlike Apple Wallet (downloadable .pkpass file), Google Wallet uses a
 * signed JWT redirect flow: we build an EventTicketClass + EventTicketObject,
 * embed both inline in a JWT, RS256-sign with the service account's private
 * key, and hand back a URL like
 *   https://pay.google.com/gp/v/save/{jwt}
 *
 * The "inline class" approach means we never have to call the Wallet API
 * to pre-create classes — Google dedupes by class ID on the fly. That
 * lets us skip `google/apiclient` and ship a much smaller dependency
 * footprint (just `firebase/php-jwt`).
 *
 * Service account JSON may be supplied either as a filesystem path
 * (`GOOGLE_WALLET_SERVICE_ACCOUNT_PATH`) for local dev, or as a base64
 * env var (`GOOGLE_WALLET_SERVICE_ACCOUNT_B64`) for Railway / serverless
 * deploys. Base64 wins when both are set.
 */
class GoogleWalletPassService
{
    private const SAVE_URL_PREFIX = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly ConfigRepository $config,
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
        $issuerId = (string)$this->config->get('wallet.google.issuer_id');
        if ($issuerId === '') {
            throw new RuntimeException('Google Wallet issuer ID is not configured');
        }

        $serviceAccount = $this->loadServiceAccount();
        $privateKey = $serviceAccount['private_key'] ?? null;
        $clientEmail = $serviceAccount['client_email'] ?? null;

        if (!is_string($privateKey) || $privateKey === '') {
            throw new RuntimeException('Google service account JSON is missing "private_key"');
        }
        if (!is_string($clientEmail) || $clientEmail === '') {
            throw new RuntimeException('Google service account JSON is missing "client_email"');
        }

        $classId = $this->buildClassId($issuerId, $event);
        $objectId = $this->buildObjectId($issuerId, $attendee, $event);

        $class = $this->buildEventTicketClass($classId, $event, $organizer, $eventSettings);
        $object = $this->buildEventTicketObject($objectId, $classId, $attendee);

        $origin = (string)$this->config->get('wallet.google.origin');
        $origins = $origin !== '' ? [$origin] : [];

        $payload = [
            'iss' => $clientEmail,
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => $origins,
            'payload' => [
                'eventTicketClasses' => [$class],
                'eventTicketObjects' => [$object],
            ],
        ];

        $jwt = JWT::encode($payload, $privateKey, 'RS256');

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
            // UNDER_REVIEW lets the JWT sign + UI load even before the
            // class has been promoted to APPROVED via the business console.
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
     * Google expects a `#RRGGBB` hex string (no `rgb()` form). Mirrors the
     * Apple service's accent-color derivation but in hex.
     */
    private function deriveHexBackgroundColor(EventSettingDomainObject $eventSettings): string
    {
        $design = method_exists($eventSettings, 'getTicketDesignSettings')
            ? $eventSettings->getTicketDesignSettings()
            : null;

        if (is_array($design)) {
            $accent = $design['accent_color'] ?? null;
            if (is_string($accent) && preg_match('/^#?([0-9a-fA-F]{6})$/', $accent, $m)) {
                return '#' . strtolower($m[1]);
            }
        }

        // House Nights brand purple — used when the event hasn't set a ticket
        // design accent color.
        return '#57398e';
    }

    /**
     * Resolve the logo URL Google should fetch when rendering the pass.
     * Falls back to a known asset under APP_URL when no explicit URL is set.
     */
    private function resolveLogoUri(): ?string
    {
        $configured = (string)$this->config->get('wallet.google.logo_uri');
        if ($configured !== '') {
            return $configured;
        }

        $appUrl = rtrim((string)$this->config->get('app.url'), '/');
        if ($appUrl === '') {
            return null;
        }

        return $appUrl . '/wallet/google-wallet-logo.png';
    }

    private function safeIso(?string $date, ?string $timezone): ?string
    {
        if (!$date) {
            return null;
        }
        try {
            return Carbon::parse($date, $timezone ?: 'UTC')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadServiceAccount(): array
    {
        $b64 = (string)$this->config->get('wallet.google.service_account_b64');
        $path = (string)$this->config->get('wallet.google.service_account_path');

        $json = $this->materializeSecret(
            b64Value: $b64,
            filePath: $path,
            friendlyName: 'Google Wallet service account JSON',
        );

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google service account JSON could not be decoded');
        }

        return $decoded;
    }

    /**
     * Resolve service-account JSON contents from either a base64 env var
     * (preferred for serverless) or a filesystem path (local dev).
     */
    private function materializeSecret(
        string $b64Value,
        string $filePath,
        string $friendlyName,
    ): string {
        if ($b64Value !== '') {
            $bytes = base64_decode($b64Value, true);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException($friendlyName . ' base64 env var is invalid');
            }
            return $bytes;
        }

        if ($filePath === '') {
            throw new RuntimeException(
                $friendlyName . ' is not configured (set GOOGLE_WALLET_SERVICE_ACCOUNT_B64 or GOOGLE_WALLET_SERVICE_ACCOUNT_PATH)'
            );
        }

        $resolved = str_starts_with($filePath, '/') ? $filePath : base_path($filePath);
        if (!is_file($resolved)) {
            throw new RuntimeException($friendlyName . ' not found at ' . $resolved);
        }

        $contents = file_get_contents($resolved);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Failed to read ' . $friendlyName . ' from ' . $resolved);
        }
        return $contents;
    }
}
