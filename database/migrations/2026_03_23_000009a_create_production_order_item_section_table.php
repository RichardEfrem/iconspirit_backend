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
        Schema::create('production_order_item_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_order')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

            $table->unique(['production_order_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_item_section');
    }
};