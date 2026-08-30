<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_report_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('report_type', 40);
            $table->date('period_end');
            $table->string('status', 10)->default('draft');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'report_type', 'period_end']);
            $table->index(['store_id', 'report_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_report_statuses');
    }
};
