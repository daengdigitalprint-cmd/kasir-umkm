<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','completed','cancelled','refunded','void') NOT NULL DEFAULT 'completed'");
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("UPDATE transactions SET status = 'cancelled' WHERE status = 'void'");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending','completed','cancelled','refunded') NOT NULL DEFAULT 'completed'");
        }
    }
};
