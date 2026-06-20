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
        Schema::create('production_order_item_material', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_item_id')->constrained('production_order_item')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('material')->onDelete('cascade');
            $table->decimal('quantity', 10, 2);
            $table->decimal('cost', 10, 2);
            $table->boolean('is_deducted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_item_material');
    }
};
