<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 kesiapan production modul Absensi + Payroll:
 * - alur periode gaji draft → approved → paid (siapa & kapan, akun pembayaran, jurnal);
 * - menghapus akun user tidak lagi ikut menghapus profil & riwayat karyawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // enum('draft','locked') → string agar status alur baru bisa disimpan.
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->date('payment_date')->nullable()->after('paid_at');
            $table->string('payment_method', 10)->nullable()->after('payment_date');
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('payment_method');
        });

        // Status lama "locked" (Fase 1) setara dengan "disetujui": belum ada jurnal pembayaran.
        DB::table('payroll_periods')->where('status', 'locked')->update(['status' => 'approved']);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['approved_at', 'paid_at', 'payment_date', 'payment_method', 'journal_entry_id']);
        });

        DB::table('payroll_periods')->whereIn('status', ['approved', 'paid'])->update(['status' => 'locked']);

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->enum('status', ['draft', 'locked'])->default('draft')->change();
        });
    }
};
