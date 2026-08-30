<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','cashier','kitchen','warehouse','owner') NOT NULL DEFAULT 'cashier'");
        DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier','kitchen','warehouse','owner') NOT NULL DEFAULT 'cashier'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("UPDATE users SET role = 'cashier' WHERE role = 'kitchen'");
        DB::statement("UPDATE store_user SET role = 'cashier' WHERE role = 'kitchen'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','cashier','warehouse','owner') NOT NULL DEFAULT 'cashier'");
        DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier','warehouse','owner') NOT NULL DEFAULT 'cashier'");
    }
};
