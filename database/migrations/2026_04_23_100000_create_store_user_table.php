<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'manager', 'cashier'])->default('cashier');
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['user_id', 'store_id']);
        });

        if (Schema::hasTable('users')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('
                    INSERT INTO store_user (store_id, user_id, role, created_at, updated_at)
                    SELECT store_id, id, role, NOW(), NOW()
                    FROM users
                    WHERE store_id IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM store_user su
                        WHERE su.store_id = users.store_id AND su.user_id = users.id
                    )
                ');
            } else {
                $rows = DB::table('users')
                    ->whereNotNull('store_id')
                    ->get(['store_id', 'id', 'role']);
                foreach ($rows as $row) {
                    DB::table('store_user')->insertOrIgnore([
                        'store_id' => $row->store_id,
                        'user_id' => $row->id,
                        'role' => $row->role ?? 'cashier',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
    }
};
