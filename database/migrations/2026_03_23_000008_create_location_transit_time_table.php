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
        Schema::create('location_transit_time', function (Blueprint $table) {
            $table->id();
            $table->foreign('origin_station_id')->references('id')->on('station')->onDelete('cascade');
            $table->foreign('destination_station_id')->references('id')->on('station')->onDelete('cascade');
            $table->integer('transit_time');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_transit_time');
    }
};
