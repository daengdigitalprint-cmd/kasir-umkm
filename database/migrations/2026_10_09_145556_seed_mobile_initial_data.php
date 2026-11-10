<?php

use App\Support\MobileDefaultData;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Jalankan seed migration untuk instalasi mobile offline-first dan SQLite.
     *
     * Dilewati saat test: migrasi ini membuat setiap database test sudah berisi paket
     * "starter", satu toko, dan akun bawaan, sehingga test yang membuat datanya sendiri
     * bentrok (slug paket duplikat) atau salah menghitung jumlah toko. Test yang memang
     * menguji seed ini memanggil MobileDefaultData::ensure() sendiri.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        MobileDefaultData::ensure();
    }

    /**
     * Seed migration tidak perlu dihapus pada rollback untuk menjaga integritas data lokal.
     */
    public function down(): void
    {
        //
    }
};
