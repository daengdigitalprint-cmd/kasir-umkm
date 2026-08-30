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
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_void')->default(false)->after('status');
            $table->string('void_reason')->nullable()->after('is_void');
            $table->timestamp('void_at')->nullable()->after('void_reason');
            $table->foreignId('void_by')->nullable()->constrained('users')->nullOnDelete()->after('void_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['void_by']);
            $table->dropColumn(['is_void', 'void_reason', 'void_at', 'void_by']);
        });
    }
};
