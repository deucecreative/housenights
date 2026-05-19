<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Pdf\DTO;

final readonly class PdfDownloadResponseDTO
{
    public function __construct(
        public string $bytes,
        public string $filename,
    ) {
    }
}
