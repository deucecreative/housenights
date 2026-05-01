<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recalculate attendees_registered from actual attendee records.
 *
 * The EventStatisticsIncrementService was using raw order item quantities
 * without multiplying by tickets_per_group for group-type products.
 * The attendees table is correct (records were always created with the
 * multiplier), so we derive the true count from there.
 */
return new class extends Migration {
    public function up(): void
    {
        // Recalculate aggregate event_statistics from actual non-cancelled attendee records
        DB::statement("
            UPDATE event_statistics es
            SET attendees_registered = COALESCE(counts.attendee_count, 0)
            FROM (
                SELECT a.event_id, COUNT(*) AS attendee_count
                FROM attendees a
                JOIN orders o ON o.id = a.order_id
                WHERE a.status IN ('ACTIVE', 'AWAITING_PAYMENT')
                  AND a.deleted_at IS NULL
                  AND o.deleted_at IS NULL
                GROUP BY a.event_id
            ) counts
            WHERE es.event_id = counts.event_id
        ");

        // Recalculate daily event_daily_statistics from actual non-cancelled attendee records
        DB::statement("
            UPDATE event_daily_statistics eds
            SET attendees_registered = COALESCE(counts.attendee_count, 0)
            FROM (
                SELECT a.event_id, a.created_at::date AS stat_date, COUNT(*) AS attendee_count
                FROM attendees a
                JOIN orders o ON o.id = a.order_id
                WHERE a.status IN ('ACTIVE', 'AWAITING_PAYMENT')
                  AND a.deleted_at IS NULL
                  AND o.deleted_at IS NULL
                GROUP BY a.event_id, a.created_at::date
            ) counts
            WHERE eds.event_id = counts.event_id
              AND eds.date = counts.stat_date
        ");
    }

    public function down(): void
    {
        // Cannot reverse — the previous values were incorrect
    }
};
