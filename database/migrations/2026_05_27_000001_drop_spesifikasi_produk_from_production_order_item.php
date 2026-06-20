<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_item', function (Blueprint $table) {
            $table->dropColumn('spesifikasi_produk');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_item', function (Blueprint $table) {
            $table->text('spesifikasi_produk')->after('product_id');
        });
    }
};
