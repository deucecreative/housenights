<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Wallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Services\Domain\Wallet\AppleWalletPassService;
use Tests\TestCase;
use ZipArchive;

class AppleWalletPassServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->walletConfigured()) {
            $this->markTestSkipped(
                'Apple Wallet certificates are not configured in this environment '
                . '(check APPLE_WALLET_CERT_PATH / APPLE_WALLET_WWDR_PATH / APPLE_WALLET_CERT_PASSWORD).'
            );
        }
    }

    public function test_generates_signed_pkpass(): void
    {
        $bytes = $this->generate();

        $this->assertNotEmpty($bytes, 'Generated pkpass should not be empty');
        // ZIP magic bytes: PK\x03\x04
        $this->assertSame("\x50\x4b\x03\x04", substr($bytes, 0, 4), 'pkpass must start with ZIP "PK" magic bytes');
    }

    public function test_pkpass_contains_required_manifest(): void
    {
        $bytes = $this->generate();
        $path = $this->writeTempPkpass($bytes);

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true, 'Generated pkpass should open as a valid zip');

            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $this->assertContains('pass.json', $names);
            $this->assertContains('manifest.json', $names);
            $this->assertContains('signature', $names);
            $this->assertContains('icon.png', $names);
        } finally {
            @unlink($path);
        }
    }

    public function test_pass_json_has_correct_fields(): void
    {
        $bytes = $this->generate();
        $path = $this->writeTempPkpass($bytes);

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $passJson = $zip->getFromName('pass.json');
            $zip->close();

            $this->assertNotFalse($passJson, 'pass.json should be present in the pkpass');

            $pass = json_decode($passJson, true);
            $this->assertIsArray($pass);

            $this->assertSame('att-pub-test-001', $pass['serialNumber']);
            $this->assertSame(config('wallet.apple.pass_type_id'), $pass['passTypeIdentifier']);
            $this->assertSame(config('wallet.apple.team_id'), $pass['teamIdentifier']);
            $this->assertSame(1, $pass['formatVersion']);

            // Barcodes are present and carry the attendee public_id
            $this->assertArrayHasKey('barcodes', $pass);
            $this->assertNotEmpty($pass['barcodes']);
            $this->assertSame('PKBarcodeFormatQR', $pass['barcodes'][0]['format']);
            $this->assertSame('att-pub-test-001', $pass['barcodes'][0]['message']);

            // Legacy barcode (singular) for iOS < 9 should mirror the modern one
            $this->assertArrayHasKey('barcode', $pass);
            $this->assertSame('att-pub-test-001', $pass['barcode']['message']);

            // eventTicket structure with required field arrays
            $this->assertArrayHasKey('eventTicket', $pass);
            $this->assertNotEmpty($pass['eventTicket']['primaryFields']);
            $this->assertSame('Smoke Event', $pass['eventTicket']['primaryFields'][0]['value']);
        } finally {
            @unlink($path);
        }
    }

    private function generate(): string
    {
        $attendee = new AttendeeDomainObject();
        $attendee->setPublicId('att-pub-test-001');
        $attendee->setShortId('att-short-test-001');
        $attendee->setFirstName('Test');
        $attendee->setLastName('Attendee');
        $attendee->setEmail('test@example.com');

        $product = new ProductDomainObject();
        $product->setTitle('GA');
        $attendee->setProduct($product);

        $order = new OrderDomainObject();
        $order->setPublicId('ORD-TEST-001');
        $attendee->setOrder($order);

        $event = new EventDomainObject();
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

        /** @var AppleWalletPassService $svc */
        $svc = $this->app->make(AppleWalletPassService::class);
        return $svc->generatePass($attendee, $event, $organizer, $settings);
    }

    private function writeTempPkpass(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkpass_') . '.pkpass';
        file_put_contents($path, $bytes);
        return $path;
    }

    private function walletConfigured(): bool
    {
        $passType = (string)config('mobile-pass.apple.type_identifier');
        if ($passType === '') {
            return false;
        }

        // Cert: prefer inline b64, fall back to path
        $b64 = (string)config('mobile-pass.apple.certificate');
        $certPath = (string)config('mobile-pass.apple.certificate_path');
        $password = (string)config('mobile-pass.apple.certificate_password');

        if ($b64 !== '') {
            $certBytes = base64_decode($b64, true);
        } elseif ($certPath !== '') {
            $resolved = str_starts_with($certPath, '/') ? $certPath : base_path($certPath);
            $certBytes = is_file($resolved) ? file_get_contents($resolved) : false;
        } else {
            return false;
        }

        if ($certBytes === false) {
            return false;
        }

        // Verify the password actually matches the .p12 — otherwise tests would
        // explode at generate() time. Locally, the cert may have been rotated
        // without updating .env; skip those tests rather than fail.
        $ok = @openssl_pkcs12_read($certBytes, $unused, $password);
        return (bool)$ok;
    }
}
