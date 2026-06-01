<?php

namespace HiEvents\Services\Domain\Report\Reports;

use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Domain\Report\AbstractReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductSalesReport extends AbstractReportService
{
    protected function getSqlQuery(Carbon $startDate, Carbon $endDate): string
    {
        $startDateString = $startDate->format('Y-m-d H:i:s');
        $endDateString = $endDate->format('Y-m-d H:i:s');
        $completedStatus = OrderStatus::COMPLETED->name;

        return <<<SQL
        WITH filtered_orders AS (
            SELECT
                oi.product_id,
                oi.product_price_id,
                oi.quantity,
                oi.total_tax,
                oi.total_gross,
                oi.total_service_fee
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.status = '$completedStatus'
                AND o.event_id = :event_id
                AND o.created_at BETWEEN '$startDateString' AND '$endDateString'
                AND oi.deleted_at IS NULL
        )
        SELECT
            p.id AS product_id,
            p.title AS product_title,
            p.type AS product_type,
            pp.id AS price_tier_id,
            pp.label AS price_tier_label,
            COALESCE(SUM(fo.total_tax), 0) AS total_tax,
            COALESCE(SUM(fo.total_gross), 0) AS total_gross,
            COALESCE(SUM(fo.total_service_fee), 0) AS total_service_fees,
            COALESCE(SUM(fo.quantity), 0) AS number_sold
        FROM products p
        LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.deleted_at IS NULL
        LEFT JOIN filtered_orders fo ON fo.product_id = p.id AND fo.product_price_id = pp.id
        WHERE p.event_id = :event_id
            AND p.deleted_at IS NULL
        GROUP BY p.id, p.title, p.type, pp.id, pp.label, p."order", pp."order"
        ORDER BY p."order", pp."order"
SQL;
    }

    /**
     * For admins, enrich each product/tier row with a per-name fee breakdown
     * extracted from the order_items `taxes_and_fees_rollup` JSONB column.
     * Non-admins never receive the `fees_breakdown` key.
     */
    protected function postProcess(
        Collection $rows,
        int        $eventId,
        Carbon     $startDate,
        Carbon     $endDate,
        bool       $includeSensitive,
    ): Collection
    {
        if (!$includeSensitive) {
            return $rows;
        }

        $feeRows = $this->queryBuilder->select(
            $this->getFeeBreakdownQuery($startDate, $endDate),
            ['event_id' => $eventId],
        );

        // Map keyed by "<product_id>:<product_price_id>" => [feeName => amount]
        $feesByTier = [];
        foreach ($feeRows as $feeRow) {
            $key = $feeRow->product_id . ':' . ($feeRow->product_price_id ?? '');
            $feesByTier[$key][$feeRow->fee_name ?? ''] = (float)$feeRow->total_collected;
        }

        return $rows->map(function (object $row) use ($feesByTier) {
            $key = $row->product_id . ':' . ($row->price_tier_id ?? '');
            $row->fees_breakdown = $feesByTier[$key] ?? (object)[];

            return $row;
        });
    }

    private function getFeeBreakdownQuery(Carbon $startDate, Carbon $endDate): string
    {
        $startDateString = $startDate->format('Y-m-d H:i:s');
        $endDateString = $endDate->format('Y-m-d H:i:s');
        $completedStatus = OrderStatus::COMPLETED->name;

        return <<<SQL
            SELECT
                oi.product_id,
                oi.product_price_id,
                fee_item->>'name' AS fee_name,
                SUM((fee_item->>'value')::numeric) AS total_collected
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            CROSS JOIN LATERAL jsonb_array_elements(
                COALESCE(oi.taxes_and_fees_rollup->'fees', '[]'::jsonb)
            ) AS fee_item
            WHERE o.status = '$completedStatus'
                AND o.event_id = :event_id
                AND o.created_at BETWEEN '$startDateString' AND '$endDateString'
                AND oi.deleted_at IS NULL
            GROUP BY oi.product_id, oi.product_price_id, fee_item->>'name'
            ORDER BY fee_item->>'name'
SQL;
    }
}
