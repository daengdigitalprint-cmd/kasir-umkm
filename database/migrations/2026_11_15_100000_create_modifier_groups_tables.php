<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modifier_groups')) {
            Schema::create('modifier_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                // single = pilih satu (mis. level gula), multiple = boleh banyak (mis. topping)
                $table->string('selection', 16)->default('multiple');
                $table->boolean('is_required')->default(false);
                $table->json('options')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['store_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('modifier_group_product')) {
            Schema::create('modifier_group_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->unique(['modifier_group_id', 'product_id']);
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_product');
        Schema::dropIfExists('modifier_groups');
    }
};
