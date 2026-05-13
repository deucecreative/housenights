<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Wallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Services\Domain\Wallet\GoogleWalletPassService;
use Tests\TestCase;

class GoogleWalletPassServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->walletConfigured()) {
            $this->markTestSkipped(
                'Google Wallet is not configured in this environment '
                . '(check GOOGLE_WALLET_ISSUER_ID and the service account file/env).'
            );
        }
    }

    public function test_generates_save_link_starting_with_pay_google_com(): void
    {
        $url = $this->generate();

        $this->assertNotEmpty($url);
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);

        $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));
        $this->assertNotEmpty($jwt, 'JWT segment should follow the save URL prefix');
        $this->assertSame(2, substr_count($jwt, '.'), 'JWT must have exactly two dot separators');
    }

    public function test_jwt_payload_contains_correct_ids(): void
    {
        $url = $this->generate();
        $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments, 'JWT must have 3 segments');

        $payload = json_decode($this->b64UrlDecode($segments[1]), true);
        $this->assertIsArray($payload);

        $this->assertSame('google', $payload['aud']);
        $this->assertSame('savetowallet', $payload['typ']);

        $this->assertArrayHasKey('payload', $payload);
        $this->assertArrayHasKey('eventTicketClasses', $payload['payload']);
        $this->assertArrayHasKey('eventTicketObjects', $payload['payload']);

        $object = $payload['payload']['eventTicketObjects'][0] ?? null;
        $this->assertIsArray($object);

        // Object ID embeds the attendee ID (101) and event ID (7)
        $this->assertStringContainsString('attendee-101', $object['id']);
        $this->assertStringContainsString('event-7', $object['id']);

        // Barcode value is the attendee's public_id
        $this->assertSame('QR_CODE', $object['barcode']['type']);
        $this->assertSame('att-pub-test-001', $object['barcode']['value']);
        $this->assertSame('att-short-test-001', $object['barcode']['alternateText']);

        // Class ID is referenced from the object
        $this->assertSame($object['classId'], $payload['payload']['eventTicketClasses'][0]['id']);
        $this->assertStringContainsString('event-7', $object['classId']);
    }

    public function test_jwt_is_rs256_signed(): void
    {
        $url = $this->generate();
        $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments, 'JWT must have 3 segments');

        $header = json_decode($this->b64UrlDecode($segments[0]), true);
        $this->assertIsArray($header);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        // The signature segment must be non-empty (RS256 over the head+payload)
        $this->assertNotEmpty($segments[2]);
    }

    private function generate(): string
    {
        $attendee = new AttendeeDomainObject();
        $attendee->setId(101);
        $attendee->setPublicId('att-pub-test-001');
        $attendee->setShortId('att-short-test-001');
        $attendee->setFirstName('Test');
        $attendee->setLastName('Attendee');
        $attendee->setEmail('test@example.com');

        $product = new ProductDomainObject();
        $product->setTitle('GA');
        $attendee->setProduct($product);

        $event = new EventDomainObject();
        $event->setId(7);
        $event->setTitle('Smoke Event');
        $event->setStartDate('2026-07-04 20:00:00');
        $event->setTimezone('UTC');

        $organizer = new OrganizerDomainObject();
        $organizer->setName('Test Org');
        $organizer->setEmail('org@example.com');

        $settings = new EventSettingDomainObject();
        $settings->setLocationDetails([
            'venue_name' => 'Test Venue',
            'address_line_1' => '1 Test St',
            'city' => 'Testville',
            'country' => 'US',
        ]);
        $settings->setSupportEmail('support@example.com');

        /** @var GoogleWalletPassService $svc */
        $svc = $this->app->make(GoogleWalletPassService::class);
        return $svc->generateSaveLink($attendee, $event, $organizer, $settings);
    }

    private function b64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private function walletConfigured(): bool
    {
        $issuerId = (string)config('wallet.google.issuer_id');
        if ($issuerId === '') {
            return false;
        }

        $b64 = (string)config('wallet.google.service_account_b64');
        if ($b64 !== '') {
            return true;
        }

        $path = (string)config('wallet.google.service_account_path');
        if ($path === '') {
            return false;
        }
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        return is_file($resolved);
    }
}
