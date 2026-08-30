<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cashier_pin_hash')->nullable()->after('password');
            $table->timestamp('cashier_pin_set_at')->nullable()->after('cashier_pin_hash');
            $table->boolean('can_login_pos')->default(false)->after('cashier_pin_set_at');
        });

        // Migrate data from cashiers table if it exists
        if (Schema::hasTable('cashiers')) {
            $cashiers = DB::table('cashiers')
                ->where('is_active', true)
                ->get(['store_id', 'name', 'pin_hash', 'pin_set_at', 'can_login_pos']);

            foreach ($cashiers as $cashier) {
                // Try to find matching user by name in the same store's members
                $userId = DB::table('store_user')
                    ->where('store_user.store_id', $cashier->store_id)
                    ->join('users', 'users.id', '=', 'store_user.user_id')
                    ->where('users.name', $cashier->name)
                    ->value('users.id');

                if ($userId) {
                    DB::table('users')->where('id', $userId)->update([
                        'cashier_pin_hash' => $cashier->pin_hash,
                        'cashier_pin_set_at' => $cashier->pin_set_at,
                        'can_login_pos' => ! empty($cashier->can_login_pos),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cashier_pin_hash', 'cashier_pin_set_at', 'can_login_pos']);
        });
    }
};
