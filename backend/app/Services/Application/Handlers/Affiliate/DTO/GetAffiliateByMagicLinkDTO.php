<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate\DTO;

readonly class GetAffiliateByMagicLinkDTO
{
    public function __construct(
        public string $token,
    ) {
    }
}
