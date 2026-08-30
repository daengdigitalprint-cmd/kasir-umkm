<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();

            $table->timestamp('clock_in')->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_in_accuracy', 8, 2)->nullable();

            $table->timestamp('clock_out')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_accuracy', 8, 2)->nullable();

            $table->string('status', 20)->default('present');
            $table->string('source', 20)->default('manual');
            $table->unsignedBigInteger('cash_shift_id')->nullable();
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leave_minutes')->default(0);
            $table->decimal('hours_worked', 6, 2)->nullable();
            $table->decimal('overtime_hours', 6, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['store_id', 'work_date']);
            $table->index(['cash_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
