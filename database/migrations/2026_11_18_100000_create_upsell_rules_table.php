<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F8 Saran tambahan menu (upsell): pasangan manual "pesan A → tawarkan B".
     * Saran otomatis "sering dibeli bersama" dihitung dari transaksi, tidak disimpan.
     */
    public function up(): void
    {
        Schema::create('upsell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('suggested_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'trigger_product_id', 'suggested_product_id'], 'upsell_rules_pair_unique');
        });

        // Menu Saran Menu untuk role yang sudah boleh membuka Diskon.
        $roles = DB::table('role_menu_permissions')->where('menu_key', 'discounts')->pluck('role')->unique()->all();
        foreach ($roles as $role) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => $role, 'menu_key' => 'upsell'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        \App\Models\RoleMenuPermission::flushMenuCache();
    }

    public function down(): void
    {
        DB::table('role_menu_permissions')->where('menu_key', 'upsell')->delete();
        \App\Models\RoleMenuPermission::flushMenuCache();
        Schema::dropIfExists('upsell_rules');
    }
};
