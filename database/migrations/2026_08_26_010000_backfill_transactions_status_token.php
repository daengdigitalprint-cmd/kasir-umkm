<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Pastikan kolom status_token selalu terisi untuk semua transaksi,
        // termasuk transaksi POS biasa yang sebelumnya null.
        $idSet = DB::table('transactions')
            ->whereNull('status_token')
            ->orderBy('id')
            ->pluck('id');

        foreach ($idSet as $id) {
            DB::table('transactions')
                ->where('id', $id)
                ->update(['status_token' => Str::random(58).'-'.$id]);
        }
    }

    public function down(): void
    {
        // Tidak mengosongkan kembali token agar tidak merusak link yang sudah dibagikan.
        // Hanya kolom status_token lama untuk transaksi scan-meja/guest dipertahankan.
    }
};
