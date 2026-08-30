<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_category_id')
                ->nullable()
                ->after('store_id')
                ->constrained('product_categories')
                ->nullOnDelete();
        });

        $pairs = DB::table('products')
            ->select('store_id', 'category')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->get();

        foreach ($pairs as $row) {
            $existing = DB::table('product_categories')
                ->where('store_id', $row->store_id)
                ->where('name', $row->category)
                ->first();

            if ($existing) {
                $catId = $existing->id;
            } else {
                $catId = DB::table('product_categories')->insertGetId([
                    'store_id' => $row->store_id,
                    'name' => $row->category,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('products')
                ->where('store_id', $row->store_id)
                ->where('category', $row->category)
                ->update(['product_category_id' => $catId]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['product_category_id']);
            $table->dropColumn('product_category_id');
        });

        Schema::dropIfExists('product_categories');
    }
};
