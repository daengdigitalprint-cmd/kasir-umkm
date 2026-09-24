<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Di MySQL, ALTER TABLE MODIFY COLUMN (migration add_warehouse/kitchen/owner role)
 * sudah memperluas enum users.role. Di SQLite (dipakai di test), enum diimplementasikan
 * sebagai CHECK constraint yang tidak bisa diubah via ALTER TABLE, sehingga insert
 * role 'warehouse', 'kitchen', dan 'owner' gagal. Migration ini recreate tabel users
 * di SQLite agar CHECK constraint mencakup semua role yang valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $schema = array_column(Schema::getColumns('users'), 'name');

        $select = implode(', ', array_map(fn (string $column) => '"'.str_replace('"', '""', $column).'"', $schema));

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('CREATE TABLE "users_new" (
            "id" integer primary key autoincrement not null,
            "name" varchar not null,
            "email" varchar not null,
            "business_type" varchar(32) not null default \'general\',
            "email_verified_at" datetime null,
            "password" varchar not null,
            "cashier_pin_hash" varchar null,
            "cashier_pin_set_at" timestamp null,
            "can_login_pos" tinyint(1) not null default 0,
            "remember_token" varchar null,
            "created_at" datetime null,
            "updated_at" datetime null,
            "store_id" integer null references "stores"("id") on delete set null,
            "role" varchar not null default \'cashier\',
            "is_active" tinyint(1) not null default 1,
            "is_super_admin" tinyint(1) not null default 0
        )');

        DB::statement("INSERT INTO \"users_new\" ({$select}) SELECT {$select} FROM \"users\"");
        DB::statement('DROP TABLE "users"');
        DB::statement('ALTER TABLE "users_new" RENAME TO "users"');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Tidak perlu rollback — CHECK constraint lama hanya berlaku di SQLite test environment.
    }
};
