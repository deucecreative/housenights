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
use ZipArchive;

/**
 * Generates a signed Apple Wallet (.pkpass) bundle for a ticket.
 *
 * Why this is a hand-rolled service instead of using
 * thenextweb/passgenerator: that library uses Flysystem v1 APIs
 * (`getDriver()->getAdapter()->getPathPrefix()`) that no longer exist on
 * Laravel 12 / Flysystem v3. We shell out to `openssl` for the PKCS#12
 * extract + PKCS#7 detached signature so the pipeline works on any host
 * that ships an `openssl` binary (Railway included).
 *
 * The .p12 must use modern crypto (AES-256-CBC, PBKDF2). macOS Keychain
 * exports default to RC2-40 + legacy MAC which require OpenSSL's legacy
 * provider — Railway's minimal image doesn't load it. Re-encrypt locally
 * with `openssl pkcs12 -export -keypbe AES-256-CBC -certpbe AES-256-CBC
 * -macalg sha256` before deploying.
 *
 * Cert + WWDR may be supplied either as filesystem paths
 * (`APPLE_WALLET_CERT_PATH` / `APPLE_WALLET_WWDR_PATH`) for local dev,
 * or as base64-encoded env vars (`APPLE_WALLET_CERT_B64` /
 * `APPLE_WALLET_WWDR_B64`) for serverless-style hosting. Base64 wins
 * when both are set.
 */
class AppleWalletPassService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Generate a signed .pkpass and return the raw bytes.
     */
    public function generatePass(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): string {
        $passDefinition = $this->buildPassDefinition($attendee, $event, $organizer, $eventSettings);
        $passJson = json_encode($passDefinition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($passJson === false) {
            throw new RuntimeException('Failed to encode pass.json for Apple Wallet pass');
        }

        $assets = $this->collectAssets();

        $workDir = $this->makeTempDir();

        try {
            // Write pass.json + assets to temp dir
            file_put_contents($workDir . '/pass.json', $passJson);
            foreach ($assets as $name => $path) {
                copy($path, $workDir . '/' . $name);
            }

            // Build manifest.json (sha1 hashes of all files)
            $manifest = ['pass.json' => sha1($passJson)];
            foreach ($assets as $name => $path) {
                $manifest[$name] = sha1_file($path);
            }
            $manifestJson = json_encode((object)$manifest, JSON_UNESCAPED_SLASHES);
            file_put_contents($workDir . '/manifest.json', $manifestJson);

            // Sign manifest -> signature (DER PKCS#7)
            $this->signManifest($workDir . '/manifest.json', $workDir . '/signature');

            // Zip everything into a .pkpass
            return $this->zipPass($workDir, array_merge(['pass.json', 'manifest.json', 'signature'], array_keys($assets)));
        } finally {
            $this->rrmdir($workDir);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPassDefinition(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
    ): array {
        $passTypeId = (string)$this->config->get('wallet.apple.pass_type_id');
        $teamId = (string)$this->config->get('wallet.apple.team_id');

        $startDate = $event->getStartDate();
        $startIso = null;
        if ($startDate) {
            $tz = $event->getTimezone() ?: 'UTC';
            try {
                // Apple's pass validator is strict about ISO 8601: it accepts the
                // Z suffix for UTC reliably but can reject the equivalent +00:00 form.
                // Convert to UTC and emit with explicit Z.
                $startIso = Carbon::parse($startDate, $tz)->utc()->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                $startIso = null;
            }
        }

        $fullName = trim($attendee->getFirstName() . ' ' . $attendee->getLastName());
        $productTitle = $attendee->getProduct()?->getTitle() ?? __('Ticket');
        $venue = $eventSettings->getAddressString() ?: (string)__('Online Event');

        $orderPublicId = $attendee->getOrder()?->getPublicId();

        $backFields = [];
        if ($orderPublicId) {
            $backFields[] = [
                'key' => 'order',
                'label' => (string)__('Order Number'),
                'value' => (string)$orderPublicId,
            ];
        }
        if ($attendee->getEmail()) {
            $backFields[] = [
                'key' => 'attendee_email',
                'label' => (string)__('Attendee Email'),
                'value' => (string)$attendee->getEmail(),
            ];
        }
        if ($eventSettings->getSupportEmail()) {
            $backFields[] = [
                'key' => 'support_email',
                'label' => (string)__('Support'),
                'value' => (string)$eventSettings->getSupportEmail(),
            ];
        }

        $secondaryFields = [
            [
                'key' => 'name',
                'label' => (string)__('Name'),
                'value' => $fullName ?: $attendee->getEmail() ?: 'Attendee',
            ],
        ];

        if ($startIso) {
            $secondaryFields[] = [
                'key' => 'date',
                'label' => (string)__('Date'),
                'value' => $startIso,
                'dateStyle' => 'PKDateStyleMedium',
                'timeStyle' => 'PKTimeStyleShort',
            ];
        }

        // Barcode payload (same content as the inline QR — public_id)
        $barcodePayload = $attendee->getPublicId();
        $barcode = [
            'format' => 'PKBarcodeFormatQR',
            'message' => $barcodePayload,
            'messageEncoding' => 'iso-8859-1',
            'altText' => $barcodePayload,
        ];

        $pass = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $passTypeId,
            'teamIdentifier' => $teamId,
            'serialNumber' => $attendee->getPublicId(),
            'organizationName' => $organizer->getName(),
            'description' => trim($event->getTitle() . ' — ' . $fullName),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'backgroundColor' => $this->deriveBackgroundColor($eventSettings),
            'labelColor' => 'rgb(255, 255, 255)',
            // Both `barcode` (legacy, iOS < 9) and `barcodes` (modern) for compatibility
            'barcode' => $barcode,
            'barcodes' => [$barcode],
            'eventTicket' => [
                'primaryFields' => [
                    [
                        'key' => 'event',
                        'label' => (string)__('Event'),
                        'value' => $event->getTitle(),
                    ],
                ],
                'secondaryFields' => $secondaryFields,
                'auxiliaryFields' => [
                    [
                        'key' => 'ticket',
                        'label' => (string)__('Ticket'),
                        'value' => $productTitle,
                    ],
                    [
                        'key' => 'venue',
                        'label' => (string)__('Venue'),
                        'value' => $venue,
                    ],
                ],
                'backFields' => $backFields,
            ],
        ];

        if ($startIso) {
            $pass['relevantDate'] = $startIso;
        }

        // Locations (lock-screen relevance) — optional
        $locationDetails = $eventSettings->getLocationDetails();
        if (is_array($locationDetails)) {
            $lat = $locationDetails['lat'] ?? $locationDetails['latitude'] ?? null;
            $lng = $locationDetails['lng'] ?? $locationDetails['longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $pass['locations'] = [[
                    'latitude' => (float)$lat,
                    'longitude' => (float)$lng,
                ]];
            }
        }

        return $pass;
    }

    private function deriveBackgroundColor(EventSettingDomainObject $eventSettings): string
    {
        $design = method_exists($eventSettings, 'getTicketDesignSettings')
            ? $eventSettings->getTicketDesignSettings()
            : null;

        if (is_array($design)) {
            $accent = $design['accent_color'] ?? null;
            if (is_string($accent) && preg_match('/^#?([0-9a-fA-F]{6})$/', $accent, $m)) {
                $hex = $m[1];
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                return sprintf('rgb(%d, %d, %d)', $r, $g, $b);
            }
        }

        // House Nights brand purple — used when the event hasn't set a ticket
        // design accent color.
        return 'rgb(87, 57, 142)';
    }

    /**
     * @return array<string,string> filename => absolute path
     */
    private function collectAssets(): array
    {
        $imagesDir = storage_path('app/wallet/apple/images');
        $assets = [];

        $candidates = [
            'icon.png',
            'icon@2x.png',
            'logo.png',
            'logo@2x.png',
            'strip.png',
            'strip@2x.png',
            'background.png',
            'background@2x.png',
            'thumbnail.png',
            'thumbnail@2x.png',
        ];

        foreach ($candidates as $name) {
            $path = $imagesDir . '/' . $name;
            if (is_file($path)) {
                $assets[$name] = $path;
            }
        }

        // icon.png and icon@2x.png are required by Apple — at minimum
        if (!isset($assets['icon.png']) || !isset($assets['icon@2x.png'])) {
            throw new RuntimeException(
                'Apple Wallet pass icon assets missing in ' . $imagesDir
                . ' (icon.png and icon@2x.png are required).'
            );
        }

        return $assets;
    }

    private function signManifest(string $manifestPath, string $signaturePath): void
    {
        $certPassword = (string)$this->config->get('wallet.apple.cert_password');

        // Materialize cert + WWDR to disk — either by decoding a base64 env var
        // or by using the configured filesystem path. The base64 form is for
        // Railway / serverless deploys where secrets live in env vars.
        $tmpDir = $this->makeTempDir();
        try {
            $certPath = $this->materializeSecret(
                b64Value: (string)$this->config->get('wallet.apple.cert_b64'),
                filePath: (string)$this->config->get('wallet.apple.cert_path'),
                targetPath: $tmpDir . '/pass-cert.p12',
                friendlyName: 'Apple Wallet pass certificate',
            );

            $wwdrPath = $this->materializeSecret(
                b64Value: (string)$this->config->get('wallet.apple.wwdr_b64'),
                filePath: (string)$this->config->get('wallet.apple.wwdr_path'),
                targetPath: $tmpDir . '/wwdr.pem',
                friendlyName: 'Apple WWDR certificate',
            );

            $certPemPath = $tmpDir . '/cert.pem';
            $keyPemPath = $tmpDir . '/key.pem';

            // The .p12 must use modern ciphers (no -legacy here). If you see
            // "unsupported" errors here in production, the .p12 was likely
            // exported from macOS Keychain — re-encrypt with AES-256-CBC.
            $this->runOpenSsl(
                [
                    'pkcs12',
                    '-in', $certPath,
                    '-passin', 'pass:' . $certPassword,
                    '-nokeys',
                    '-out', $certPemPath,
                ],
                'extract certificate from p12'
            );

            $this->runOpenSsl(
                [
                    'pkcs12',
                    '-in', $certPath,
                    '-passin', 'pass:' . $certPassword,
                    '-passout', 'pass:' . $certPassword,
                    '-nocerts',
                    '-out', $keyPemPath,
                ],
                'extract private key from p12'
            );

            // Sign manifest.json — DER-encoded detached CMS SignedData v3.
            // We use `openssl cms` (not `openssl smime`) because the legacy
            // smime command produces PKCS#7 SignedData v1, which iOS Wallet
            // silently rejects on iOS 14+. CMS v3 is the modern format Apple
            // requires for .pkpass signatures. Explicitly request SHA-256
            // digest (modern default; SHA-1 is deprecated).
            $this->runOpenSsl(
                [
                    'cms',
                    '-binary',
                    '-sign',
                    '-certfile', $wwdrPath,
                    '-signer', $certPemPath,
                    '-inkey', $keyPemPath,
                    '-in', $manifestPath,
                    '-out', $signaturePath,
                    '-outform', 'DER',
                    '-passin', 'pass:' . $certPassword,
                    '-md', 'sha256',
                ],
                'sign manifest.json'
            );
        } finally {
            $this->rrmdir($tmpDir);
        }
    }

    /**
     * Resolve a cert secret from either a base64 env var (preferred) or a
     * filesystem path, materializing it to $targetPath so openssl can read it.
     */
    private function materializeSecret(
        string $b64Value,
        string $filePath,
        string $targetPath,
        string $friendlyName,
    ): string {
        if ($b64Value !== '') {
            $bytes = base64_decode($b64Value, true);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException($friendlyName . ' base64 env var is invalid');
            }
            if (file_put_contents($targetPath, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write ' . $friendlyName . ' to ' . $targetPath);
            }
            chmod($targetPath, 0600);
            return $targetPath;
        }

        if ($filePath === '') {
            throw new RuntimeException($friendlyName . ' is not configured (set CERT_B64 or CERT_PATH)');
        }

        $resolved = $this->resolveCertPath($filePath);
        if (!is_file($resolved)) {
            throw new RuntimeException($friendlyName . ' not found at ' . $resolved);
        }
        return $resolved;
    }

    /**
     * Run openssl CLI; throws on non-zero exit with stderr captured.
     *
     * @param list<string> $args
     */
    private function runOpenSsl(array $args, string $stage): void
    {
        $cmd = array_merge(['openssl'], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to launch openssl for ' . $stage);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            // Scrub the password from any error output before throwing
            $password = (string)$this->config->get('wallet.apple.cert_password');
            $safeStderr = $password !== '' ? str_replace($password, '***', $stderr) : $stderr;
            $safeStdout = $password !== '' ? str_replace($password, '***', $stdout) : $stdout;
            throw new RuntimeException(
                sprintf('openssl failed to %s (exit %d): %s%s', $stage, $exit, $safeStderr, $safeStdout)
            );
        }
    }

    /**
     * @param list<string> $files
     */
    private function zipPass(string $workDir, array $files): string
    {
        $zipPath = $workDir . '/ticket.pkpass';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to open zip archive for pkpass');
        }

        foreach (array_unique($files) as $file) {
            $full = $workDir . '/' . $file;
            if (!is_file($full)) {
                continue;
            }
            $zip->addFile($full, $file);
        }

        $zip->close();

        $bytes = file_get_contents($zipPath);
        if ($bytes === false) {
            throw new RuntimeException('Failed to read generated .pkpass file');
        }

        return $bytes;
    }

    private function resolveCertPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/hi-events-pkpass-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create temporary directory at ' . $dir);
        }
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
