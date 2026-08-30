<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom tarif lembur dan BPJS di tabel stores untuk modul payroll.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Tarif lembur (multiplier dari upah/jam)
            $table->decimal('overtime_rate_first_hour', 4, 2)->default(1.50)->after('late_penalty_per_minute');
            $table->decimal('overtime_rate_next_hour', 4, 2)->default(2.00)->after('overtime_rate_first_hour');
            $table->decimal('overtime_rate_holiday', 4, 2)->default(2.00)->after('overtime_rate_next_hour');

            // BPJS (persentase)
            $table->boolean('bpjs_enabled')->default(false)->after('overtime_rate_holiday');
            $table->decimal('bpjs_health_employee', 5, 2)->default(1.00)->after('bpjs_enabled'); // 1% karyawan
            $table->decimal('bpjs_health_employer', 5, 2)->default(4.00)->after('bpjs_health_employee'); // 4% perusahaan
            $table->decimal('bpjs_jht_employee', 5, 2)->default(2.00)->after('bpjs_health_employer'); // 2% JHT karyawan
            $table->decimal('bpjs_jht_employer', 5, 2)->default(3.70)->after('bpjs_jht_employee'); // 3.7% JHT perusahaan
            $table->decimal('bpjs_jp_employee', 5, 2)->default(1.00)->after('bpjs_jht_employer'); // 1% JP karyawan
            $table->decimal('bpjs_jp_employer', 5, 2)->default(2.00)->after('bpjs_jp_employee'); // 2% JP perusahaan
            $table->decimal('bpjs_jkk', 5, 2)->default(0.24)->after('bpjs_jp_employer'); // 0.24% JKK (risiko rendah)
            $table->decimal('bpjs_jkm', 5, 2)->default(0.30)->after('bpjs_jkk'); // 0.3% JKM
        });
    }

    /**
     * Hapus kolom tarif lembur dan BPJS.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_rate_first_hour',
                'overtime_rate_next_hour',
                'overtime_rate_holiday',
                'bpjs_enabled',
                'bpjs_health_employee',
                'bpjs_health_employer',
                'bpjs_jht_employee',
                'bpjs_jht_employer',
                'bpjs_jp_employee',
                'bpjs_jp_employer',
                'bpjs_jkk',
                'bpjs_jkm',
            ]);
        });
    }
};
