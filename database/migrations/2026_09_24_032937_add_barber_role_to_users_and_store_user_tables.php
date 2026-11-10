<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan role 'barber' (teknisi barbershop / salon).
 *
 * - MySQL: enum users.role & store_user.role diperluas via ALTER TABLE.
 * - SQLite (test): users.role sudah varchar tanpa CHECK (migrasi 2026_09_22),
 *   tetapi store_user.role masih CHECK constraint sehingga perlu recreate tabel.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'manager', 'cashier', 'kitchen', 'warehouse', 'owner', 'barber'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $enum = implode("','", self::ROLES);
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");

            return;
        }

        if ($driver === 'sqlite') {
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
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $enum = implode("','", array_filter(self::ROLES, fn (string $role) => $role !== 'barber'));
            DB::statement('UPDATE users SET role = \'cashier\' WHERE role = \'barber\'');
            DB::statement('UPDATE store_user SET role = \'cashier\' WHERE role = \'barber\'');
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('{$enum}') NOT NULL DEFAULT 'cashier'");
        }

        // SQLite: tidak perlu rollback — hanya lingkungan test.
    }
};
