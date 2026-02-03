<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->string('affiliate_term', 50)->nullable()->after('default_allow_attendee_self_edit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->dropColumn('affiliate_term');
        });
    }
};
