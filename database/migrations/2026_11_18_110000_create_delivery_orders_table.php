<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F10 Pesanan Antar mandiri: ongkir di transaksi + data pengantaran (alamat,
     * kurir karyawan, status Disiapkan → Diantar → Selesai).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('delivery_fee', 15, 2)->default(0)->after('tip_amount');
        });

        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address');
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->foreignId('courier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('preparing')->index();
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        // Menu Pengantaran untuk role yang sudah boleh membuka kasir.
        $roles = DB::table('role_menu_permissions')->where('menu_key', 'pos')->pluck('role')->unique()->all();
        foreach ($roles as $role) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => $role, 'menu_key' => 'deliveries'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        \App\Models\RoleMenuPermission::flushMenuCache();
    }

    public function down(): void
    {
        DB::table('role_menu_permissions')->where('menu_key', 'deliveries')->delete();
        \App\Models\RoleMenuPermission::flushMenuCache();

        Schema::dropIfExists('delivery_orders');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('delivery_fee');
        });
    }
};
