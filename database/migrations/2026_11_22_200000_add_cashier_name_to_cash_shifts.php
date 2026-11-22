<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nama kasir disimpan permanen di setiap shift (saat dibuka), supaya laporan tidak ikut berubah
 * ketika nama akun login diganti. Kasus nyata: satu akun dipakai bergantian dan namanya
 * diganti-ganti per kasir, sehingga semua shift lama tampil dengan nama akun terbaru.
 *
 * Data lama:
 * 1. Shift tanpa employee_id dihubungkan ke karyawan di toko yang sama bila awalan nomor shift
 *    (mis. "AISYAH-060") cocok dengan tepat satu karyawan (nama depan).
 * 2. cashier_name diisi dari nama karyawan, atau dari awalan nomor shift (yang dibentuk dari
 *    nama akun saat shift dibuka). Nomor lama format "STR-YYYYMMDD-XXXX" dibiarkan kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->string('cashier_name', 100)->nullable()->after('employee_id');
        });

        $code = fn (string $name): string => Str::upper(Str::slug(Str::before($name, ' '), ''));

        // Peta awalan → karyawan per toko (hanya yang unik).
        $employeesByStore = [];
        if (Schema::hasTable('employees')) {
            foreach (DB::table('employees')->get(['id', 'store_id', 'name']) as $e) {
                $employeesByStore[$e->store_id][$code((string) $e->name)][] = $e;
            }
        }

        DB::table('cash_shifts')
            ->select(['id', 'store_id', 'employee_id', 'shift_number'])
            ->orderBy('id')
            ->chunkById(500, function ($shifts) use ($employeesByStore) {
                foreach ($shifts as $s) {
                    $prefix = Str::contains((string) $s->shift_number, '-')
                        ? Str::beforeLast((string) $s->shift_number, '-')
                        : '';
                    $isNameCode = $prefix !== '' && $prefix !== 'STR' && preg_match('/^[A-Z0-9]+$/', $prefix);

                    $employeeId = $s->employee_id;
                    if (! $employeeId && $isNameCode) {
                        $matches = $employeesByStore[$s->store_id][$prefix] ?? [];
                        if (count($matches) === 1) {
                            $employeeId = $matches[0]->id;
                        }
                    }

                    $name = null;
                    if ($employeeId) {
                        $name = DB::table('employees')->where('id', $employeeId)->value('name');
                    }
                    if (! $name && $isNameCode) {
                        $name = Str::title(Str::lower($prefix));
                    }

                    $update = [];
                    if ($employeeId && ! $s->employee_id) {
                        $update['employee_id'] = $employeeId;
                    }
                    if ($name) {
                        $update['cashier_name'] = Str::limit($name, 100, '');
                    }
                    if ($update) {
                        DB::table('cash_shifts')->where('id', $s->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn('cashier_name');
        });
    }
};
