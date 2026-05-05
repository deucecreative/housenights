<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recalculate attendees_registered to match the runtime invariant.
 *
 * The previous migration (2026_05_01_091236) counted attendee rows with
 * status IN ('ACTIVE', 'AWAITING_PAYMENT') regardless of the parent order's
 * status. That over-counted by including AWAITING_PAYMENT attendees from
 * RESERVED, ABANDONED, and AWAITING_OFFLINE_PAYMENT orders — none of which
 * have been incremented into stats by the runtime EventStatisticsIncrementService
 * (which only fires for COMPLETED orders).
 *
 * Source of truth: ACTIVE attendees on COMPLETED, non-deleted orders.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            UPDATE event_statistics es
            SET attendees_registered = (
                SELECT COUNT(*)
                FROM attendees a
                JOIN orders o ON o.id = a.order_id
                WHERE a.event_id = es.event_id
                  AND a.status = 'ACTIVE'
                  AND o.status = 'COMPLETED'
                  AND a.deleted_at IS NULL
                  AND o.deleted_at IS NULL
            )
            WHERE es.deleted_at IS NULL
        ");

        DB::statement("
            UPDATE event_daily_statistics eds
            SET attendees_registered = (
                SELECT COUNT(*)
                FROM attendees a
                JOIN orders o ON o.id = a.order_id
                WHERE a.event_id = eds.event_id
                  AND a.created_at::date = eds.date
                  AND a.status = 'ACTIVE'
                  AND o.status = 'COMPLETED'
                  AND a.deleted_at IS NULL
                  AND o.deleted_at IS NULL
            )
            WHERE eds.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        // Cannot reverse — the previous values were incorrect
    }
};
