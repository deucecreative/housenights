<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Interfaces\IsSortable;
use HiEvents\DomainObjects\SortingAndFiltering\AllowedSorts;

class AffiliateDomainObject extends Generated\AffiliateDomainObjectAbstract implements IsSortable
{
    final public const TOTAL_TICKETS = 'total_tickets';

    protected int $total_tickets = 0;

    public static function getAllowedSorts(): AllowedSorts
    {
        return new AllowedSorts(
            [
                self::CREATED_AT => [
                    'asc' => __('Oldest First'),
                    'desc' => __('Newest First'),
                ],
                self::NAME => [
                    'asc' => __('Name A-Z'),
                    'desc' => __('Name Z-A'),
                ],
                self::TOTAL_SALES => [
                    'asc' => __('Orders Ascending'),
                    'desc' => __('Orders Descending'),
                ],
                self::TOTAL_TICKETS => [
                    'asc' => __('Tickets Ascending'),
                    'desc' => __('Tickets Descending'),
                ],
                self::TOTAL_SALES_GROSS => [
                    'asc' => __('Revenue Ascending'),
                    'desc' => __('Revenue Descending'),
                ],
            ],
        );
    }

    public static function getDefaultSort(): string
    {
        return self::TOTAL_TICKETS;
    }

    public static function getDefaultSortDirection(): string
    {
        return 'desc';
    }

    public function setTotalTickets(int $total_tickets): self
    {
        $this->total_tickets = $total_tickets;
        return $this;
    }

    public function getTotalTickets(): int
    {
        return $this->total_tickets;
    }
}
