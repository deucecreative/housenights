<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organizer_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['organizer_id', 'user_id']);

            $table->foreign('organizer_id')
                ->references('id')
                ->on('organizers')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_user');
    }
};
