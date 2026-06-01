<?php

namespace HiEvents\Services\Domain\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Application\Handlers\Event\DTO\EventStatsRequestDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\EventStatsResponseDTO;
use HiEvents\Services\Domain\Event\DTO\EventCheckInStatsResponseDTO;
use HiEvents\Services\Domain\Event\DTO\EventDailyStatsResponseDTO;
use HiEvents\Services\Domain\Event\DTO\EventTaxFeeBreakdownItemDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

readonly class EventStatsFetchService
{
    public function __construct(
        private DatabaseManager $db,
    )
    {
    }

    public function getEventStats(EventStatsRequestDTO $requestData): EventStatsResponseDTO
    {
        $eventId = $requestData->event_id;

        // Aggregate total statistics for the event for all time
        $totalsQuery = <<<SQL
        SELECT
            SUM(es.products_sold) AS total_products_sold,
            SUM(es.orders_created) AS total_orders,
            SUM(es.sales_total_gross) AS total_gross_sales,
            SUM(es.total_tax) AS total_tax,
            SUM(es.total_fee) AS total_fees,
            SUM(es.total_views) AS total_views,
            SUM(es.total_refunded) AS total_refunded,
            SUM(es.attendees_registered) AS attendees_registered

        FROM event_statistics es
        WHERE es.event_id = :eventId
          AND es.deleted_at IS NULL;
    SQL;

        // Execute the totals and comparison queries
        $totalsResult = $this->db->selectOne($totalsQuery, ['eventId' => $eventId]);

        // Use the results to populate the response DTO
        return new EventStatsResponseDTO(
            daily_stats: $this->getDailyEventStats($requestData),
            start_date: $requestData->start_date,
            end_date: $requestData->end_date,
            total_products_sold: $totalsResult->total_products_sold ?? 0,
            total_attendees_registered: $totalsResult->attendees_registered ?? 0,
            total_orders: $totalsResult->total_orders ?? 0,
            total_gross_sales: $totalsResult->total_gross_sales ?? 0,
            total_fees: $totalsResult->total_fees ?? 0,
            total_tax: $totalsResult->total_tax ?? 0,
            total_views: $totalsResult->total_views ?? 0,
            total_refunded: $totalsResult->total_refunded ?? 0,
            taxes_and_fees_breakdown: $requestData->include_breakdown
                ? $this->getTaxAndFeeBreakdown($eventId)
                : null,
        );
    }

    /**
     * Aggregate the per-name tax and fee breakdown for an event from the
     * `taxes_and_fees_rollup` JSONB column on completed orders.
     *
     * Refunds are netted off proportionally per order using the same approach as
     * EventStatisticsRefundService (each item is scaled by 1 - total_refunded/total_gross),
     * so these figures reconcile with the refund-adjusted aggregate total_tax/total_fee.
     * Refunds are not stored per tax/fee name, so a proportional split is the best available.
     *
     * @return Collection<EventTaxFeeBreakdownItemDTO>
     */
    public function getTaxAndFeeBreakdown(int $eventId): Collection
    {
        $completedStatus = OrderStatus::COMPLETED->name;

        // Per-order factor that nets off refunds proportionally. Defaults to 1 (no refund)
        // when total_gross is 0 to avoid division by zero on free orders.
        $refundFactor = "COALESCE(1 - o.total_refunded / NULLIF(o.total_gross, 0), 1)";

        $query = <<<SQL
            WITH items AS (
                SELECT
                    'TAX' AS kind,
                    tax_item->>'name' AS name,
                    (tax_item->>'rate')::numeric AS rate,
                    (tax_item->>'value')::numeric * $refundFactor AS value
                FROM orders o
                CROSS JOIN LATERAL jsonb_array_elements(
                    COALESCE(o.taxes_and_fees_rollup->'taxes', '[]'::jsonb)
                ) AS tax_item
                WHERE o.event_id = :eventId
                    AND o.status = '$completedStatus'
                    AND o.deleted_at IS NULL

                UNION ALL

                SELECT
                    'FEE' AS kind,
                    fee_item->>'name' AS name,
                    (fee_item->>'rate')::numeric AS rate,
                    (fee_item->>'value')::numeric * $refundFactor AS value
                FROM orders o
                CROSS JOIN LATERAL jsonb_array_elements(
                    COALESCE(o.taxes_and_fees_rollup->'fees', '[]'::jsonb)
                ) AS fee_item
                WHERE o.event_id = :eventIdFee
                    AND o.status = '$completedStatus'
                    AND o.deleted_at IS NULL
            )
            SELECT
                kind,
                name,
                rate,
                SUM(value) AS total_collected,
                COUNT(*) AS order_count
            FROM items
            GROUP BY kind, name, rate
            ORDER BY kind, name;
        SQL;

        $results = $this->db->select($query, [
            'eventId' => $eventId,
            'eventIdFee' => $eventId,
        ]);

        return collect($results)->map(fn(object $row) => new EventTaxFeeBreakdownItemDTO(
            kind: $row->kind,
            name: $row->name ?? '',
            rate: (float)($row->rate ?? 0),
            total_collected: (float)($row->total_collected ?? 0),
            order_count: (int)($row->order_count ?? 0),
        ));
    }

    public function getDailyEventStats(EventStatsRequestDTO $requestData): Collection
    {
        $eventId = $requestData->event_id;

        $startDate = $requestData->start_date;
        $endDate = $requestData->end_date;

        $query = <<<SQL
            WITH date_series AS (
              SELECT date::date
              FROM generate_series(
                :startDate::date,
                :endDate::date,
                '1 day'
              ) AS gs(date)
            )
            SELECT
              ds.date,
              COALESCE(SUM(eds.total_fee), 0) AS total_fees,
              COALESCE(SUM(eds.total_tax), 0) AS total_tax,
              COALESCE(SUM(eds.sales_total_gross), 0) AS total_sales_gross,
              COALESCE(SUM(eds.orders_created), 0) AS orders_created,
              COALESCE(SUM(eds.products_sold), 0) AS products_sold,
              COALESCE(SUM(eds.attendees_registered), 0) AS attendees_registered,
              COALESCE(SUM(eds.total_refunded), 0) AS total_refunded
            FROM date_series ds
            LEFT JOIN event_daily_statistics eds ON ds.date = eds.date AND eds.deleted_at IS NULL AND eds.event_id = :eventId
            GROUP BY ds.date
            ORDER BY ds.date ASC;
        SQL;

        $results = $this->db->select($query, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'eventId' => $eventId,
        ]);

        $currentTime = Carbon::now('UTC')->toTimeString();

        return collect($results)->map(function (object $result) use ($currentTime) {
            $dateTimeWithCurrentTime = (new Carbon($result->date))->setTimezone('UTC')->format('Y-m-d') . ' ' . $currentTime;

            return new EventDailyStatsResponseDTO(
                date: $dateTimeWithCurrentTime,
                total_fees: $result->total_fees,
                total_tax: $result->total_tax,
                total_sales_gross: $result->total_sales_gross,
                products_sold: $result->products_sold,
                orders_created: $result->orders_created,
                attendees_registered: $result->attendees_registered,
                total_refunded: $result->total_refunded,
            );
        });
    }

    public function getCheckedInStats(int $eventId): EventCheckInStatsResponseDTO
    {
        $query = <<<SQL
            SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN attendees.checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_in_count
            FROM attendees
            INNER JOIN orders ON orders.id = attendees.order_id
            WHERE orders.event_id = {$eventId}
              AND orders.status = 'COMPLETED'
              AND attendees.status = 'ACTIVE';
        SQL;

        $result = $this->db->select($query)[0];

        return new EventCheckInStatsResponseDTO(
            total_checked_in_attendees: $result->checked_in_count ?? 0,
            total_attendees: $result->total_count ?? 0,
        );
    }
}
