<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mapping prefix shift_number -> employee_id berdasarkan nama employee
        $mapping = [
            'AISYAH' => 3, // Aisyah
            'HAERUL' => 2, // haerul hadi
            'KASIR1' => 5, // Kasir1
            'NURAENI' => 4, // Nuraeni
        ];

        foreach ($mapping as $prefix => $employeeId) {
            DB::table('cash_shifts')
                ->whereNull('employee_id')
                ->where('shift_number', 'like', $prefix.'-%')
                ->update(['employee_id' => $employeeId]);
        }
    }

    public function down(): void
    {
        // Tidak ada rollback otomatis yang aman untuk perubahan data historis
    }
};
