<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F6 Permintaan Stok antar lokasi + masa aktif Modul Central Kitchen (R2).
     *
     * Peminta (biasanya outlet) meminta barang ke pemasok (biasanya Gudang Pusat).
     * Saat disetujui, permintaan menjadi Transfer Stok yang langsung dikirim.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('central_kitchen_until')->nullable()->after('attendance_payroll_until');
        });

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 40)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('status', 20)->default('requested')->index();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('stock_transfer_id')->nullable()->constrained('stock_transfers')->nullOnDelete();
            $table->timestamps();

            $table->index(['to_store_id', 'status']);
            $table->index(['from_store_id', 'status']);
        });

        Schema::create('stock_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('quantity_requested', 18, 4);
            $table->decimal('quantity_approved', 18, 4)->nullable();
            $table->decimal('stock_at_request', 18, 4)->default(0);
            $table->decimal('min_stock_at_request', 18, 4)->default(0);
            $table->string('base_unit', 20)->nullable();
            $table->timestamps();
        });

        // Menu Permintaan Stok untuk role yang sudah boleh membuka Transfer Stok.
        $roles = DB::table('role_menu_permissions')->where('menu_key', 'stock_transfers')->pluck('role')->unique()->all();
        foreach ($roles as $role) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role' => $role, 'menu_key' => 'stock_requests'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        \App\Models\RoleMenuPermission::flushMenuCache();
    }

    public function down(): void
    {
        DB::table('role_menu_permissions')->where('menu_key', 'stock_requests')->delete();
        \App\Models\RoleMenuPermission::flushMenuCache();

        Schema::dropIfExists('stock_request_lines');
        Schema::dropIfExists('stock_requests');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('central_kitchen_until');
        });
    }
};
