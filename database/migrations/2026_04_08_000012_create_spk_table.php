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
        Schema::create('spk', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_spk')->unique();
            $table->date('tanggal_terbit');
            $table->foreignId('production_order_id')->unique()->constrained('production_order')->onDelete('cascade');
            $table->foreignId('assigned_factory')->nullable()->constrained('factory_location')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spk');
    }
};
