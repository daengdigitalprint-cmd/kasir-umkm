<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kurir toko tidak perlu punya akun: cukup namanya. Pengantaran menyimpan nama kurir
 * sebagai teks; kolom lama `courier_user_id` dibiarkan untuk data yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('courier_name', 120)->nullable()->after('courier_user_id');
        });

        // Pengantaran lama yang kurirnya dipilih dari akun pengguna: salin namanya agar tetap tampil.
        DB::table('delivery_orders')
            ->whereNotNull('courier_user_id')
            ->orderBy('id')
            ->select(['id', 'courier_user_id'])
            ->chunkById(200, function ($rows) {
                $names = DB::table('users')->whereIn('id', $rows->pluck('courier_user_id')->unique())->pluck('name', 'id');
                foreach ($rows as $row) {
                    $name = $names[$row->courier_user_id] ?? null;
                    if ($name !== null && trim((string) $name) !== '') {
                        DB::table('delivery_orders')->where('id', $row->id)->update(['courier_name' => mb_substr(trim((string) $name), 0, 120)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('courier_name');
        });
    }
};
