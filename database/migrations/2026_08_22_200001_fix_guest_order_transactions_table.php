<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Make transactions.user_id nullable (guest orders have no user)
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // 2. Add 'qris' to payment_method enum (used by Scan Meja guest orders)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY payment_method ENUM('cash','card','ewallet','transfer','qris','other') DEFAULT 'cash'");
        }

        // 3. Add missing columns to queue_entries (order_type, table_number, items)
        if (! Schema::hasColumn('queue_entries', 'order_type')) {
            Schema::table('queue_entries', function (Blueprint $table) {
                $table->string('order_type', 20)->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('queue_entries', 'table_number')) {
            Schema::table('queue_entries', function (Blueprint $table) {
                $table->string('table_number', 64)->nullable()->after('order_type');
            });
        }

        if (! Schema::hasColumn('queue_entries', 'items')) {
            Schema::table('queue_entries', function (Blueprint $table) {
                $table->json('items')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        // Restore payment_method enum
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY payment_method ENUM('cash','card','ewallet','transfer','other') DEFAULT 'cash'");
        }

        // Restore user_id as non-nullable
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            if (Schema::hasColumn('queue_entries', 'items')) {
                $table->dropColumn('items');
            }
            if (Schema::hasColumn('queue_entries', 'table_number')) {
                $table->dropColumn('table_number');
            }
            if (Schema::hasColumn('queue_entries', 'order_type')) {
                $table->dropColumn('order_type');
            }
        });
    }
};
