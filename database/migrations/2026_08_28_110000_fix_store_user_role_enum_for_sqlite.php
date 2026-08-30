<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Di MySQL, ALTER TABLE MODIFY COLUMN sudah menangani perluasan enum.
 * Di SQLite (dipakai di test), enum diimplementasikan sebagai CHECK constraint
 * yang tidak berubah saat ALTER TABLE. Migration ini recreate tabel store_user
 * di SQLite agar CHECK constraint mencakup semua role termasuk 'owner' dan 'kitchen'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        // Simpan semua data pivot yang ada.
        $rows = DB::table('store_user')->get();

        // Drop dan recreate tabel dengan enum lengkap.
        Schema::drop('store_user');

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'manager', 'cashier', 'warehouse', 'kitchen', 'owner'])->default('cashier');
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['user_id', 'store_id']);
        });

        // Kembalikan data yang tersimpan.
        foreach ($rows as $row) {
            DB::table('store_user')->insert((array) $row);
        }
    }

    public function down(): void
    {
        // Tidak perlu rollback — hanya untuk SQLite test environment.
    }
};
