<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi ulang nama kategori produk (kolom `category`) dari kategori terpilih
 * (`product_category_id`). Sebelumnya sebagian produk punya id kategori tapi
 * namanya kosong, sehingga daftar produk menampilkan "Tanpa kategori" dan
 * diskon per kategori tidak berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_categories')
            ->orderBy('id')
            ->select(['id', 'name'])
            ->chunkById(500, function ($categories) {
                foreach ($categories as $category) {
                    DB::table('products')
                        ->where('product_category_id', $category->id)
                        ->where(function ($q) use ($category) {
                            $q->whereNull('category')->orWhere('category', '!=', $category->name);
                        })
                        ->update(['category' => $category->name]);
                }
            });
    }

    public function down(): void
    {
        // Data hanya dirapikan; tidak ada yang perlu dikembalikan.
    }
};
