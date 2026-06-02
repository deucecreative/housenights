<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recomputes event_statistics (and event_daily_statistics) from the underlying
 * order data, which is the source of truth. Use this to backfill/heal the stored
 * aggregates, which are otherwise maintained incrementally and can drift
 * (e.g. cancellations historically did not decrement the financial totals).
 *
 * Only order-derived fields are rewritten. View counters (total_views /
 * unique_views) are tracked separately and are always preserved.
 *
 * Definitions (match the incremental services' intended semantics):
 *  - Only COMPLETED orders contribute to financials/products/attendees.
 *  - Gross is net of refunds; tax/fee are netted proportionally per order
 *    (1 - total_refunded / total_gross), mirroring EventStatisticsRefundService.
 *  - CANCELLED orders contribute only to orders_cancelled.
 */
class RecomputeEventStatisticsCommand extends Command
{
    protected $signature = 'stats:recompute
        {--event= : Only recompute a single event ID}
        {--dry-run : Show what would change without writing}
        {--no-daily : Skip event_daily_statistics, only recompute the aggregate}';

    protected $description = 'Recompute event_statistics (and daily statistics) from order data';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $withDaily = !$this->option('no-daily');
        $completed = OrderStatus::COMPLETED->name;
        $cancelled = OrderStatus::CANCELLED->name;
        $active = AttendeeStatus::ACTIVE->name;

        $eventIds = $this->resolveEventIds();

        if (empty($eventIds)) {
            $this->info('No events to recompute.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sRecomputing statistics for %d event(s)%s...',
            $dryRun ? '[DRY RUN] ' : '',
            count($eventIds),
            $withDaily ? ' (incl. daily)' : ' (aggregate only)',
        ));

        $changed = 0;

        foreach ($eventIds as $eventId) {
            try {
                $didChange = $this->recomputeEvent((int)$eventId, $completed, $cancelled, $active, $withDaily, $dryRun);
                if ($didChange) {
                    $changed++;
                }
            } catch (Throwable $e) {
                $this->error("Event {$eventId}: failed - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d event(s) %s.',
            $dryRun ? '[DRY RUN] ' : '',
            $changed,
            $dryRun ? 'would change' : 'updated',
        ));

        return self::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function resolveEventIds(): array
    {
        if ($this->option('event')) {
            return [(int)$this->option('event')];
        }

        return DB::table('orders')
            ->distinct()
            ->pluck('event_id')
            ->merge(DB::table('event_statistics')->whereNull('deleted_at')->pluck('event_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function recomputeEvent(
        int    $eventId,
        string $completed,
        string $cancelled,
        string $active,
        bool   $withDaily,
        bool   $dryRun,
    ): bool
    {
        $target = $this->computeAggregate($eventId, $completed, $cancelled, $active);
        $existing = DB::table('event_statistics')
            ->where('event_id', $eventId)
            ->whereNull('deleted_at')
            ->first();

        $changed = $this->diff($existing, $target);

        if ($changed && $this->output->isVerbose()) {
            $this->line("Event {$eventId}: " . $this->describeDiff($existing, $target));
        }

        if (!$dryRun) {
            DB::transaction(function () use ($eventId, $target, $existing, $withDaily, $completed, $cancelled, $active) {
                $this->writeAggregate($eventId, $target, $existing);
                if ($withDaily) {
                    $this->recomputeDaily($eventId, $completed, $cancelled, $active);
                }
            });
        }

        return $changed;
    }

    /**
     * @return array<string, float|int>
     */
    private function computeAggregate(int $eventId, string $completed, string $cancelled, string $active): array
    {
        // Refund factor mirrors EventStatisticsRefundService: net tax/fee proportionally.
        $refundFactor = "COALESCE(1 - o.total_refunded / NULLIF(o.total_gross, 0), 1)";

        $financials = DB::selectOne("
            SELECT
                COUNT(*) AS orders_created,
                COALESCE(SUM(o.total_gross - o.total_refunded), 0) AS sales_total_gross,
                COALESCE(SUM(o.total_before_additions), 0) AS sales_total_before_additions,
                COALESCE(SUM(o.total_refunded), 0) AS total_refunded,
                COALESCE(SUM(o.total_tax * $refundFactor), 0) AS total_tax,
                COALESCE(SUM(o.total_fee * $refundFactor), 0) AS total_fee
            FROM orders o
            WHERE o.event_id = :eventId AND o.status = :status AND o.deleted_at IS NULL
        ", ['eventId' => $eventId, 'status' => $completed]);

        $cancelledCount = DB::selectOne("
            SELECT COUNT(*) AS c FROM orders
            WHERE event_id = :eventId AND status = :status AND deleted_at IS NULL
        ", ['eventId' => $eventId, 'status' => $cancelled]);

        $products = DB::selectOne("
            SELECT COALESCE(SUM(oi.quantity), 0) AS c
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.event_id = :eventId AND o.status = :status
              AND o.deleted_at IS NULL AND oi.deleted_at IS NULL
        ", ['eventId' => $eventId, 'status' => $completed]);

        $attendees = DB::selectOne("
            SELECT COUNT(*) AS c
            FROM attendees a
            JOIN orders o ON o.id = a.order_id
            WHERE o.event_id = :eventId AND o.status = :ostatus
              AND a.status = :astatus AND a.deleted_at IS NULL AND o.deleted_at IS NULL
        ", ['eventId' => $eventId, 'ostatus' => $completed, 'astatus' => $active]);

        return [
            'orders_created' => (int)$financials->orders_created,
            'orders_cancelled' => (int)$cancelledCount->c,
            'sales_total_gross' => round((float)$financials->sales_total_gross, 2),
            'sales_total_before_additions' => round((float)$financials->sales_total_before_additions, 2),
            'total_refunded' => round((float)$financials->total_refunded, 2),
            'total_tax' => round((float)$financials->total_tax, 2),
            'total_fee' => round((float)$financials->total_fee, 2),
            'products_sold' => (int)$products->c,
            'attendees_registered' => (int)$attendees->c,
        ];
    }

    private function writeAggregate(int $eventId, array $target, ?object $existing): void
    {
        if ($existing) {
            DB::table('event_statistics')
                ->where('id', $existing->id)
                ->update($target + [
                    'version' => ($existing->version ?? 0) + 1,
                    'updated_at' => Carbon::now(),
                ]);
            return;
        }

        DB::table('event_statistics')->insert($target + [
            'event_id' => $eventId,
            'unique_views' => 0,
            'total_views' => 0,
            'version' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function recomputeDaily(int $eventId, string $completed, string $cancelled, string $active): void
    {
        $refundFactor = "COALESCE(1 - o.total_refunded / NULLIF(o.total_gross, 0), 1)";

        $financials = DB::select("
            SELECT
                (o.created_at)::date AS d,
                COUNT(*) AS orders_created,
                COALESCE(SUM(o.total_gross - o.total_refunded), 0) AS sales_total_gross,
                COALESCE(SUM(o.total_before_additions), 0) AS sales_total_before_additions,
                COALESCE(SUM(o.total_refunded), 0) AS total_refunded,
                COALESCE(SUM(o.total_tax * $refundFactor), 0) AS total_tax,
                COALESCE(SUM(o.total_fee * $refundFactor), 0) AS total_fee
            FROM orders o
            WHERE o.event_id = :eventId AND o.status = :status AND o.deleted_at IS NULL
            GROUP BY (o.created_at)::date
        ", ['eventId' => $eventId, 'status' => $completed]);

        $cancelledByDate = $this->keyByDate(DB::select("
            SELECT (created_at)::date AS d, COUNT(*) AS c
            FROM orders
            WHERE event_id = :eventId AND status = :status AND deleted_at IS NULL
            GROUP BY (created_at)::date
        ", ['eventId' => $eventId, 'status' => $cancelled]));

        $productsByDate = $this->keyByDate(DB::select("
            SELECT (o.created_at)::date AS d, COALESCE(SUM(oi.quantity), 0) AS c
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.event_id = :eventId AND o.status = :status
              AND o.deleted_at IS NULL AND oi.deleted_at IS NULL
            GROUP BY (o.created_at)::date
        ", ['eventId' => $eventId, 'status' => $completed]));

        $attendeesByDate = $this->keyByDate(DB::select("
            SELECT (o.created_at)::date AS d, COUNT(*) AS c
            FROM attendees a
            JOIN orders o ON o.id = a.order_id
            WHERE o.event_id = :eventId AND o.status = :ostatus
              AND a.status = :astatus AND a.deleted_at IS NULL AND o.deleted_at IS NULL
            GROUP BY (o.created_at)::date
        ", ['eventId' => $eventId, 'ostatus' => $completed, 'astatus' => $active]));

        $targets = [];
        foreach ($financials as $row) {
            $date = (string)$row->d;
            $targets[$date] = [
                'orders_created' => (int)$row->orders_created,
                'sales_total_gross' => round((float)$row->sales_total_gross, 2),
                'sales_total_before_additions' => round((float)$row->sales_total_before_additions, 2),
                'total_refunded' => round((float)$row->total_refunded, 2),
                'total_tax' => round((float)$row->total_tax, 2),
                'total_fee' => round((float)$row->total_fee, 2),
                'products_sold' => (int)($productsByDate[$date] ?? 0),
                'attendees_registered' => (int)($attendeesByDate[$date] ?? 0),
                'orders_cancelled' => (int)($cancelledByDate[$date] ?? 0),
            ];
        }

        // Dates that have only cancelled orders still need a row (orders_cancelled count).
        foreach ($cancelledByDate as $date => $count) {
            if (!isset($targets[$date])) {
                $targets[$date] = $this->zeroDaily() + ['orders_cancelled' => (int)$count];
            }
        }

        $existingByDate = DB::table('event_daily_statistics')
            ->where('event_id', $eventId)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn($row) => (string)$row->date);

        // Zero out any existing dates that no longer have qualifying orders.
        foreach ($existingByDate as $date => $row) {
            if (!isset($targets[(string)$date])) {
                $targets[(string)$date] = $this->zeroDaily();
            }
        }

        foreach ($targets as $date => $vals) {
            $existing = $existingByDate[$date] ?? null;
            if ($existing) {
                DB::table('event_daily_statistics')
                    ->where('id', $existing->id)
                    ->update($vals + [
                        'version' => ($existing->version ?? 0) + 1,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                DB::table('event_daily_statistics')->insert($vals + [
                    'event_id' => $eventId,
                    'date' => $date,
                    'total_views' => 0,
                    'version' => 1,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * @return array<string, float|int>
     */
    private function zeroDaily(): array
    {
        return [
            'orders_created' => 0,
            'orders_cancelled' => 0,
            'sales_total_gross' => 0,
            'sales_total_before_additions' => 0,
            'total_refunded' => 0,
            'total_tax' => 0,
            'total_fee' => 0,
            'products_sold' => 0,
            'attendees_registered' => 0,
        ];
    }

    /**
     * @param array<int, object> $rows
     * @return array<string, float|int>
     */
    private function keyByDate(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row->d] = $row->c;
        }
        return $map;
    }

    private function diff(?object $existing, array $target): bool
    {
        if (!$existing) {
            return true;
        }

        foreach ($target as $key => $value) {
            if (abs((float)$existing->{$key} - (float)$value) > 0.001) {
                return true;
            }
        }

        return false;
    }

    private function describeDiff(?object $existing, array $target): string
    {
        $parts = [];
        foreach (['sales_total_gross', 'total_tax', 'total_fee', 'orders_cancelled'] as $key) {
            $was = $existing->{$key} ?? 0;
            $parts[] = "{$key} {$was} -> {$target[$key]}";
        }
        return implode(', ', $parts);
    }
}
