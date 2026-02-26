<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('affiliate_link_visibility', 20)
                ->default('SHOW_ALWAYS')
                ->after('is_hidden_without_promo_code');
        });

        // Migrate existing data
        DB::table('products')
            ->where('is_hidden_without_affiliate_link', true)
            ->update(['affiliate_link_visibility' => 'AFFILIATE_ONLY']);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_hidden_without_affiliate_link');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hidden_without_affiliate_link')
                ->default(false)
                ->after('is_hidden_without_promo_code');
        });

        // Migrate data back
        DB::table('products')
            ->where('affiliate_link_visibility', 'AFFILIATE_ONLY')
            ->update(['is_hidden_without_affiliate_link' => true]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('affiliate_link_visibility');
        });
    }
};
