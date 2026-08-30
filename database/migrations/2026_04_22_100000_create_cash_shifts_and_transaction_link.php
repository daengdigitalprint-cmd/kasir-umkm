<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shift_number', 40)->unique();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('closing_cash_counted', 15, 2)->nullable();
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'user_id', 'closed_at']);
            $table->index(['store_id', 'opened_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_shift_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['cash_shift_id']);
            $table->dropColumn('cash_shift_id');
        });

        Schema::dropIfExists('cash_shifts');
    }
};
