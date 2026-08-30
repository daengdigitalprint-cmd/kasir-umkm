<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->text('quarantine_reason')->nullable()->after('status');
            $table->foreignId('quarantined_by')->nullable()->after('quarantine_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('quarantined_at')->nullable()->after('quarantined_by');
            $table->foreignId('released_by')->nullable()->after('quarantined_at')->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable()->after('released_by');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropForeign(['quarantined_by']);
            $table->dropForeign(['released_by']);
            $table->dropColumn(['quarantine_reason', 'quarantined_by', 'quarantined_at', 'released_by', 'released_at']);
        });
    }
};
