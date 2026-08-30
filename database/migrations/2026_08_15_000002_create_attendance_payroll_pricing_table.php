<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_payroll_pricing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_price')->default(49000);
            $table->unsignedBigInteger('yearly_price')->default(490000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_payroll_pricing');
    }
};
