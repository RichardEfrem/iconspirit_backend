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
        Schema::create('production_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_item_id')->constrained('production_order_item')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('station')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('team')->restrictOnDelete();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status')->default('scheduled');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->timestamps();

            // Indexes on columns used in scheduling queue checks, canStart logic,
            // and team queue ordering (ORDER BY and < comparisons on start_time).
            $table->index('status', 'idx_production_schedules_status');
            $table->index('start_time', 'idx_production_schedules_start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_schedules');
    }
};
