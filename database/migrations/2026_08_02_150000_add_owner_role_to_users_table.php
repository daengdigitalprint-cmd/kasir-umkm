<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','cashier','manager','warehouse','owner') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier','warehouse','owner') NOT NULL DEFAULT 'cashier'");
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("UPDATE users SET role = 'cashier' WHERE role = 'owner'");
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','cashier','manager','warehouse') NOT NULL DEFAULT 'cashier'");
            DB::statement("UPDATE store_user SET role = 'cashier' WHERE role IN ('warehouse', 'owner')");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier') NOT NULL DEFAULT 'cashier'");
        }
    }
};
