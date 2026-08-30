<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('pin_hash')->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->boolean('can_login_pos')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });

        // Pindahkan data kasir lama dari modul absensi agar modul PIN bisa berdiri sendiri.
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'pin_hash')) {
            $rows = DB::table('employees')
                ->where('is_active', true)
                ->get(['store_id', 'name', 'position', 'pin_hash', 'pin_set_at', 'can_login_pos'])
                ->map(fn ($e) => (array) $e)
                ->toArray();

            foreach ($rows as $row) {
                DB::table('cashiers')->insert([
                    'store_id' => $row['store_id'],
                    'name' => $row['name'],
                    'position' => $row['position'] ?? null,
                    'pin_hash' => $row['pin_hash'] ?? null,
                    'pin_set_at' => $row['pin_set_at'] ?? null,
                    'can_login_pos' => ! empty($row['can_login_pos']),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cashiers');
    }
};
