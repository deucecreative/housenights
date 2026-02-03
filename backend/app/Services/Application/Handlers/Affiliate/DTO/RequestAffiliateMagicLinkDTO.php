<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Affiliate\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class RequestAffiliateMagicLinkDTO extends BaseDTO
{
    public function __construct(
        public string $email,
    ) {
    }
}
