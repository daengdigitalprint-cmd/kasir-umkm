<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->boolean('is_paid')->default(true)->after('notes');
            $table->string('payment_method', 20)->nullable()->after('is_paid');
            $table->decimal('amount_paid', 15, 2)->default(0)->after('payment_method');
            $table->date('due_date')->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'payment_method', 'amount_paid', 'due_date']);
        });
    }
};
