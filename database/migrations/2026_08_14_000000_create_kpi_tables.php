<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target KPI per outlet per periode (auto-growth)
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // Format: YYYY-MM
            $table->decimal('growth_percentage', 5, 2)->default(10.00);
            $table->decimal('target_revenue', 15, 2)->nullable();
            $table->integer('target_transactions')->nullable();
            $table->decimal('target_margin', 5, 2)->nullable();
            $table->integer('target_customers')->nullable();
            $table->json('baseline_data')->nullable();
            $table->boolean('auto_generated')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'period']);
            $table->index('period');
        });

        // Snapshot harian performa outlet
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->json('metrics');
            $table->timestamps();

            $table->unique(['store_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
        Schema::dropIfExists('kpi_targets');
    }
};
