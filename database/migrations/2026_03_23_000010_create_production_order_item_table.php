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
        Schema::create('production_order_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_order')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('product')->onDelete('restrict');
            $table->foreignId('production_order_item_section_id')->nullable()->constrained('production_order_item_section')->nullOnDelete();
            $table->text('spesifikasi_produk');
            $table->integer('panjang')->unsigned();
            $table->integer('tinggi')->unsigned();
            $table->integer('quantity')->unsigned();
            $table->text('keterangan')->nullable();
            $table->decimal('production_time', 8, 2)->nullable();
            $table->dateTime('production_deadline')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_item');
    }
};
