<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'allows_decimal')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('allows_decimal')->default(false);
            });
        }

        if ($driver !== 'mysql') {
            return;
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('stock_quantity', 18, 4)->default(0)->change();
                $table->decimal('min_stock_level', 18, 4)->default(0)->change();
            });
        }

        if (Schema::hasTable('transaction_items')) {
            Schema::table('transaction_items', function (Blueprint $table) {
                $table->decimal('quantity', 18, 4)->change();
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->decimal('quantity_change', 18, 4)->change();
                $table->decimal('balance_after', 18, 4)->nullable()->change();
            });
        }

        if (Schema::hasTable('product_batches')) {
            Schema::table('product_batches', function (Blueprint $table) {
                $table->decimal('quantity_remaining', 18, 4)->default(0)->change();
            });
        }

        if (Schema::hasTable('purchase_lines')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->decimal('quantity', 18, 4)->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            if (Schema::hasTable('purchase_lines')) {
                Schema::table('purchase_lines', function (Blueprint $table) {
                    $table->unsignedInteger('quantity')->change();
                });
            }

            if (Schema::hasTable('product_batches')) {
                Schema::table('product_batches', function (Blueprint $table) {
                    $table->unsignedInteger('quantity_remaining')->default(0)->change();
                });
            }

            if (Schema::hasTable('stock_movements')) {
                Schema::table('stock_movements', function (Blueprint $table) {
                    $table->integer('quantity_change')->change();
                    $table->unsignedInteger('balance_after')->nullable()->change();
                });
            }

            if (Schema::hasTable('transaction_items')) {
                Schema::table('transaction_items', function (Blueprint $table) {
                    $table->integer('quantity')->change();
                });
            }

            if (Schema::hasTable('products')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->integer('stock_quantity')->default(0)->change();
                    $table->integer('min_stock_level')->default(0)->change();
                });
            }
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'allows_decimal')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('allows_decimal');
            });
        }
    }
};
