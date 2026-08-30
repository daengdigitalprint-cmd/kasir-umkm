<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','cashier','manager','warehouse') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier','warehouse') NOT NULL DEFAULT 'cashier'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("UPDATE users SET role = 'cashier' WHERE role = 'warehouse'");
            DB::statement('UPDATE store_user SET role = \'cashier\' WHERE role = \'warehouse\'');
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','cashier','manager') NOT NULL DEFAULT 'cashier'");
            DB::statement("ALTER TABLE store_user MODIFY COLUMN role ENUM('admin','manager','cashier') NOT NULL DEFAULT 'cashier'");
        }
    }
};
