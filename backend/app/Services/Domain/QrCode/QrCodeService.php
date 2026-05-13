<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrCode;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererInterface;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeService
{
    /**
     * Generate a PNG-encoded QR code for the given payload.
     *
     * @param string $data The string to encode (e.g. attendee public_id)
     * @param int    $size Approximate width/height in pixels (default 400)
     * @return string Raw PNG bytes
     */
    public function generatePng(string $data, int $size = 400): string
    {
        $writer = new Writer($this->buildRenderer($size));

        return $writer->writeString(
            $data,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::M(),
        );
    }

    private function buildRenderer(int $size): RendererInterface
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            return new ImageRenderer(
                new RendererStyle($size),
                new ImagickImageBackEnd('png'),
            );
        }

        return new GDLibRenderer($size);
    }
}
