<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lembur otomatis: catatan lembur bisa dibuat sistem dari jam absen pulang
 * (source = auto, tertaut ke attendance) selain diajukan karyawan (source = request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->string('source', 20)->default('request')->after('status');
            $table->foreignId('attendance_id')->nullable()->after('employee_id')
                ->constrained('attendances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_id');
            $table->dropColumn('source');
        });
    }
};
