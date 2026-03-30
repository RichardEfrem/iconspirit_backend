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
        Schema::create('production_order', function (Blueprint $table) {
            $table->id();
            $table->string('kode_spk')->unique();
            $table->string('nama_customer');
            $table->string('alamat_customer');
            $table->date('tanggal_order');
            // The Status Column
            $table->string('status_id');
            $table->foreign('status_id')
                ->references('id')
                ->on('production_statuses')
                ->onUpdate('cascade'); // Allows you to rename status IDs if needed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order');
    }
};
