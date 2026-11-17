<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** R2 — harga add-on Modul Central Kitchen (satu baris, diatur Super Admin). */
    public function up(): void
    {
        Schema::create('central_kitchen_pricing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_price')->default(199000);
            $table->unsignedBigInteger('yearly_price')->default(1990000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_kitchen_pricing');
    }
};
