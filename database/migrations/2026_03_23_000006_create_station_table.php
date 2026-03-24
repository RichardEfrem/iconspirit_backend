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
        Schema::create('station', function (Blueprint $table) {
            $table->id();
            $table->string('nama_station');
            // This replaces both the unsignedBigInteger and the foreign() lines
            $table->foreignId('factory_location_id')->constrained('factory_location');
            $table->integer('biaya_harian');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station');
    }
};
