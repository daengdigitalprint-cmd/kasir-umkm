<?php

use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('ringkasan laporan memakai penjualan bersih yang sama dengan dashboard owner', function () {
    $store = Store::query()->create([
        'name' => 'Toko Bersih',
        'slug' => 'toko-bersih-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    // Subtotal 100.000, diskon 10.000, pajak 9.900, tip 5.000 → uang diterima 104.900.
    DB::table('transactions')->insert([
        'store_id' => $store->id,
        'user_id' => $admin->id,
        'transaction_number' => 'TXN-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 100000,
        'discount_amount' => 10000,
        'tax_amount' => 9900,
        'tip_amount' => 5000,
        'total_amount' => 104900,
        'amount_paid' => 104900,
        'change_amount' => 0,
        'created_at' => '2026-11-10 10:00:00',
        'updated_at' => '2026-11-10 10:00:00',
    ]);
    // Retur 20.000 (sebelum pajak).
    DB::table('sales_returns')->insert([
        'store_id' => $store->id,
        'return_number' => 'RTR-'.uniqid(),
        'subtotal' => 20000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 20000,
        'returned_at' => '2026-11-11 10:00:00',
        'created_at' => '2026-11-11 10:00:00',
        'updated_at' => '2026-11-11 10:00:00',
    ]);

    $this->actingAs($admin)->get('/reports?start_date=2026-11-01&end_date=2026-11-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            // 100.000 - 10.000 diskon - 20.000 retur = 70.000 (tanpa pajak & tip).
            ->where('summary.net_sales', 70000)
            // Uang diterima tetap tersedia sebagai rincian.
            ->where('summary.total_sales', 104900)
            ->where('summary.collected_not_revenue', 14900)
            ->where('summary.average_order_value', 70000)
            // 30 hari (1–30 Nov) dibanding 30 hari tepat sebelumnya (2–31 Okt).
            ->where('comparison.prev_start', '2026-10-02')
            ->where('comparison.prev_end', '2026-10-31'));
});
