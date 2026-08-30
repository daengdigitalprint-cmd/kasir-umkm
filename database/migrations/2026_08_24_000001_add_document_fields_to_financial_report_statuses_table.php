<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_report_statuses', function (Blueprint $table) {
            $table->string('report_number', 40)->nullable()->after('status');
            $table->timestamp('issued_at')->nullable()->after('report_number');
            $table->foreignId('prepared_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();
            $table->string('reviewed_by', 120)->nullable()->after('prepared_by');
        });
    }

    public function down(): void
    {
        Schema::table('financial_report_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropColumn(['report_number', 'issued_at', 'reviewed_by']);
        });
    }
};
