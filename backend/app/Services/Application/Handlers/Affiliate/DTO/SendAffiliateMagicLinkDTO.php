<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate\DTO;

readonly class SendAffiliateMagicLinkDTO
{
    public function __construct(
        public int $affiliateId,
    ) {
    }
}
