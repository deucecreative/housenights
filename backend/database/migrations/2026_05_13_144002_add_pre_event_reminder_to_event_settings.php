<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('pre_event_reminder_enabled')->default(true)->after('notify_organizer_of_new_orders');
            $table->integer('pre_event_reminder_hours')->default(24)->after('pre_event_reminder_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn(['pre_event_reminder_enabled', 'pre_event_reminder_hours']);
        });
    }
};
