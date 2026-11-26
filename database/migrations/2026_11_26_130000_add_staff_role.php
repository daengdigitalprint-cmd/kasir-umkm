<?php

use App\Models\RoleMenuPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan role 'staff' (label: Karyawan): karyawan biasa yang hanya memakai Absensi
 * (absen, riwayat, pengajuan cuti/lembur, slip gaji sendiri).
 *
 * - MySQL: enum users.role & store_user.role diperluas via ALTER TABLE.
 * - SQLite (test): users.role sudah varchar tanpa CHECK, tetapi store_user.role masih
 *   CHECK constraint sehingga tabel dibuat ulang (pola sama dengan migrasi role barber).
 * - Hak akses menu bawaan: hanya 'attendance'.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'manager', 'cashier', 'kitchen', 'warehouse', 'owner', 'barber', 'staff'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $enum = implode("','", self::ROLES);
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
        } elseif ($driver === 'sqlite') {
            $rows = DB::table('store_user')->get();

            Schema::drop('store_user');

            Schema::create('store_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->enum('role', self::ROLES)->default('cashier');
                $table->timestamps();

                $table->unique(['store_id', 'user_id']);
                $table->index(['user_id', 'store_id']);
            });

            foreach ($rows as $row) {
                DB::table('store_user')->insert((array) $row);
            }
        }

        RoleMenuPermission::query()->where('role', 'staff')->delete();
        RoleMenuPermission::create(['role' => 'staff', 'menu_key' => 'attendance']);
    }

    public function down(): void
    {
        RoleMenuPermission::query()->where('role', 'staff')->delete();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $enum = implode("','", array_filter(self::ROLES, fn (string $role) => $role !== 'staff'));
            DB::statement('UPDATE users SET role = \'cashier\' WHERE role = \'staff\'');
            DB::statement('UPDATE store_user SET role = \'cashier\' WHERE role = \'staff\'');
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
        }

        // SQLite: tidak perlu rollback, hanya lingkungan test.
    }
};
