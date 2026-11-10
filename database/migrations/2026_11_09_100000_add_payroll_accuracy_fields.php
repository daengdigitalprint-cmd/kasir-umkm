<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 kesiapan production modul Absensi + Payroll:
 * - batas upah BPJS (Kesehatan & Jaminan Pensiun) yang bisa diubah tiap tahun;
 * - opsi potong gaji untuk hari alpa / cuti tanpa gaji;
 * - penanda komponen gaji tetap (dasar BPJS, lembur, dan potongan harian);
 * - tanggal masuk / keluar karyawan untuk gaji prorata;
 * - hari libur toko dan tautan baris absensi ke pengajuan cuti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('bpjs_health_wage_cap', 15, 2)->default(12_000_000)->after('bpjs_jkm');
            $table->decimal('bpjs_jp_wage_cap', 15, 2)->default(11_086_300)->after('bpjs_health_wage_cap');
            $table->boolean('absence_deduction_enabled')->default(false)->after('bpjs_jp_wage_cap');
        });

        Schema::table('salary_components', function (Blueprint $table) {
            $table->boolean('is_fixed')->default(true)->after('type');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->date('joined_at')->nullable()->after('position');
            $table->date('resigned_at')->nullable()->after('joined_at');
        });

        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['store_id', 'date']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('cash_shift_id')
                ->constrained('leave_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_id');
        });

        Schema::dropIfExists('attendance_holidays');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['joined_at', 'resigned_at']);
        });

        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn('is_fixed');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['bpjs_health_wage_cap', 'bpjs_jp_wage_cap', 'absence_deduction_enabled']);
        });
    }
};
