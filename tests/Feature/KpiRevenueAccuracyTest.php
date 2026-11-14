<?php

use App\Models\Store;
use App\Models\User;
use App\Services\KPIService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function tokoKpi(string $nama = 'Toko KPI'): Store
{
    return Store::query()->create([
        'name' => $nama,
        'slug' => 'kpi-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

/** Transaksi dengan rincian uang eksplisit; total mengikuti rumus POS. */
function trxKpi(Store $store, array $n): int
{
    $subtotal = $n['subtotal'];
    $diskon = $n['diskon'] ?? 0;
    $pajak = $n['pajak'] ?? 0;
    $tip = $n['tip'] ?? 0;
    $kasir = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);

    return (int) DB::table('transactions')->insertGetId([
        'store_id' => $store->id,
        'user_id' => $kasir->id,
        'transaction_number' => 'TRX-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => $subtotal,
        'tax_amount' => $pajak,
        'service_charge_amount' => 0,
        'tip_amount' => $tip,
        'discount_amount' => $diskon,
        'total_amount' => $subtotal - $diskon + $pajak + $tip,
        'amount_paid' => $subtotal - $diskon + $pajak + $tip,
        'change_amount' => 0,
        'created_at' => $n['tanggal'],
        'updated_at' => $n['tanggal'],
    ]);
}

function itemKpi(int $trxId, float $jual, ?float $modal, int $qty = 1): void
{
    $storeId = (int) DB::table('transactions')->where('id', $trxId)->value('store_id');

    $productId = (int) DB::table('products')->insertGetId([
        'store_id' => $storeId,
        'name' => 'Barang KPI',
        'sku' => 'SKU-'.uniqid(),
        'price' => $jual,
        'cost_price' => $modal ?? 0,
        'stock_quantity' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transaction_items')->insert([
        'transaction_id' => $trxId,
        'product_id' => $productId,
        'product_name' => 'Barang KPI',
        'quantity' => $qty,
        'unit_price' => $jual,
        'total_price' => $jual * $qty,
        'unit_cost_snapshot' => $modal,
        'total_cost_snapshot' => $modal !== null ? $modal * $qty : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('tidak menghitung pajak dan tip sebagai pendapatan KPI', function () {
    $store = tokoKpi();
    trxKpi($store, ['subtotal' => 1_000_000, 'pajak' => 110_000, 'tip' => 50_000, 'tanggal' => '2026-10-10 10:00:00']);

    $kpi = app(KPIService::class)->calculateMonthlyKPI($store, '2026-10');

    expect($kpi['revenue'])->toBe(1_000_000.0);
});

it('margin tidak lagi menggelembung oleh pajak', function () {
    $store = tokoKpi();
    $trx = trxKpi($store, ['subtotal' => 1_000_000, 'pajak' => 110_000, 'tanggal' => '2026-10-10 10:00:00']);
    itemKpi($trx, 1_000_000, 700_000);

    $kpi = app(KPIService::class)->calculateMonthlyKPI($store, '2026-10');

    // Benar: (1.000.000 - 700.000) / 1.000.000 = 30%.
    // Cara lama memakai revenue 1.110.000 sehingga margin terbaca 36,9%.
    expect($kpi['gross_margin_percentage'])->toBe(30.0)
        ->and($kpi['gross_profit'])->toBe(300_000.0)
        ->and($kpi['cogs'])->toBe(700_000.0);
});

it('mengurangkan retur penjualan dari pendapatan KPI', function () {
    $store = tokoKpi();
    trxKpi($store, ['subtotal' => 1_000_000, 'tanggal' => '2026-10-10 10:00:00']);

    DB::table('sales_returns')->insert([
        'store_id' => $store->id,
        'return_number' => 'RTN-'.uniqid(),
        'return_type' => 'refund',
        'subtotal' => 150_000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 150_000,
        'returned_at' => '2026-10-15 10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(KPIService::class)->calculateMonthlyKPI($store, '2026-10')['revenue'])->toBe(850_000.0);
});

it('melaporkan cakupan HPP agar margin tidak dibaca mentah', function () {
    $store = tokoKpi();
    $trx = trxKpi($store, ['subtotal' => 1_000_000, 'tanggal' => '2026-10-10 10:00:00']);
    itemKpi($trx, 300_000, 180_000); // ada harga modal
    itemKpi($trx, 700_000, null);    // tanpa harga modal

    $kpi = app(KPIService::class)->calculateMonthlyKPI($store, '2026-10');

    // Berbobot omzet: 300rb dari 1jt = 30%.
    expect($kpi['cogs_coverage'])->toBe(30.0);
});

it('menyusun target otomatis dari dasar yang sama dengan realisasi', function () {
    $store = tokoKpi();
    // Bulan sebelumnya: penjualan bersih 1 juta, pajak 110rb.
    trxKpi($store, ['subtotal' => 1_000_000, 'pajak' => 110_000, 'tanggal' => '2026-09-10 10:00:00']);

    $target = app(KPIService::class)->generateAutoTarget($store, '2026-10');

    // Baseline memakai penjualan bersih, bukan 1.110.000.
    expect((float) $target->baseline_data['revenue'])->toBe(1_000_000.0);
});

it('snapshot harian memakai penjualan bersih', function () {
    $store = tokoKpi();
    trxKpi($store, ['subtotal' => 400_000, 'pajak' => 44_000, 'tanggal' => now()->setTime(10, 0)->toDateTimeString()]);

    $snapshot = app(KPIService::class)->createDailySnapshot($store);

    expect((float) $snapshot->metrics['revenue'])->toBe(400_000.0);
});
