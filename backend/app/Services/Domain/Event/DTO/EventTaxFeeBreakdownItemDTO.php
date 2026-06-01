<?php

namespace HiEvents\Services\Domain\Event\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class EventTaxFeeBreakdownItemDTO extends BaseDTO
{
    public function __construct(
        public string $kind, // 'TAX' or 'FEE'
        public string $name,
        public float  $rate,
        public float  $total_collected,
        public int    $order_count,
    )
    {
    }
}
