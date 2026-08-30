<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'has_variants')) {
            $placeAfterAllowsDecimal = Schema::hasColumn('products', 'allows_decimal');
            try {
                Schema::table('products', function (Blueprint $table) use ($placeAfterAllowsDecimal) {
                    if ($placeAfterAllowsDecimal) {
                        $table->boolean('has_variants')->default(false)->after('allows_decimal');
                    } else {
                        $table->boolean('has_variants')->default(false);
                    }
                });
            } catch (QueryException $e) {
                if (
                    ! str_contains($e->getMessage(), 'Duplicate column')
                    && ! str_contains($e->getMessage(), '1060')
                ) {
                    throw $e;
                }
            }
        }

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('sku', 255)->nullable();
                $table->string('barcode', 255)->nullable();
                $table->decimal('price', 15, 2)->nullable();
                $table->decimal('cost_price', 15, 2)->nullable();
                $table->decimal('stock_quantity', 18, 4)->default(0);
                $table->decimal('min_stock_level', 18, 4)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['store_id', 'sku']);
                $table->index(['store_id', 'barcode']);
                $table->index(['product_id', 'is_active', 'sort_order']);
            });
        }

        if (Schema::hasTable('transaction_items') && ! Schema::hasColumn('transaction_items', 'product_variant_id')) {
            Schema::table('transaction_items', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
                $table->string('variant_label', 120)->nullable()->after('product_sku');
            });
        }

        if (Schema::hasTable('stock_movements') && ! Schema::hasColumn('stock_movements', 'product_variant_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            });
        }

        if (Schema::hasTable('purchase_lines') && ! Schema::hasColumn('purchase_lines', 'product_variant_id')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_lines') && Schema::hasColumn('purchase_lines', 'product_variant_id')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_variant_id');
            });
        }

        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'product_variant_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_variant_id');
            });
        }

        if (Schema::hasTable('transaction_items')) {
            if (Schema::hasColumn('transaction_items', 'variant_label')) {
                Schema::table('transaction_items', function (Blueprint $table) {
                    $table->dropColumn('variant_label');
                });
            }
            if (Schema::hasColumn('transaction_items', 'product_variant_id')) {
                Schema::table('transaction_items', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('product_variant_id');
                });
            }
        }

        Schema::dropIfExists('product_variants');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'has_variants')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('has_variants');
            });
        }
    }
};
