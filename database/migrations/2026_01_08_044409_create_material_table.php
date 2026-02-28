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
        Schema::create('material', function (Blueprint $table) {
            $table->id();
            $table->string('kode_material')->unique()->index();
            $table->string('nama_material');
            $table->enum('satuan', ['SET', 'PCS', 'MTR', 'LEMBAR', 'BH', 'ROLL', 'BTG', 'LJR', 'DOS', 'KG', 'KLG', 'GALON', 'BKS', 'LTR']);
            $table->integer('jumlah');
            $table->integer('harga')->default(0);
            $table->string('category') -> default('kayu');
            $table->enum('status', ['IN_STOCK', 'LOW_STOCK', 'OUT_OF_STOCK'])->default('IN_STOCK');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material');
    }
};
