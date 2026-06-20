<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customer')->nullOnDelete();
            $table->string('order_id')->unique();
            $table->string('nama_customer');
            $table->string('alamat_customer');
            $table->date('tanggal_order');
            $table->string('status_id');
            $table->boolean('is_urgent')->default(false);
            $table->date('production_deadline')->nullable();
            $table->dateTime('production_start')->nullable();
            $table->dateTime('estimated_end')->nullable();
            $table->timestamp('material_eta')->nullable();
            $table->foreign('status_id')
                ->references('id')
                ->on('production_statuses')
                ->onUpdate('cascade');
            $table->timestamps();

            // Indexes on columns used in WHERE filters across all dashboard queries.
            // status_id uses ->foreign() (not ->foreignId()) so the index must be added manually.
            $table->index('status_id', 'idx_production_order_status_id');
            $table->index('tanggal_order', 'idx_production_order_tanggal_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order');
    }
};
