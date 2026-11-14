<?php

use App\Models\Store;
use App\Models\User;
use App\Services\OwnerDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Buat satu transaksi dengan rincian uang yang eksplisit.
 * total_amount mengikuti rumus POS: subtotal - diskon + pajak + servis + tip.
 */
function buatTransaksi(Store $store, array $n): int
{
    $subtotal = $n['subtotal'];
    $diskon = $n['diskon'] ?? 0;
    $pajak = $n['pajak'] ?? 0;
    $servis = $n['servis'] ?? 0;
    $tip = $n['tip'] ?? 0;
    $total = $subtotal - $diskon + $pajak + $servis + $tip;

    $kasir = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);

    return (int) DB::table('transactions')->insertGetId([
        'store_id' => $store->id,
        'user_id' => $kasir->id,
        'transaction_number' => 'TRX-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => $subtotal,
        'tax_amount' => $pajak,
        'service_charge_amount' => $servis,
        'tip_amount' => $tip,
        'discount_amount' => $diskon,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'created_at' => $n['tanggal'],
        'updated_at' => $n['tanggal'],
    ]);
}

function buatItem(int $transactionId, float $hargaJual, ?float $hargaModal, int $qty = 1): void
{
    $storeId = (int) DB::table('transactions')->where('id', $transactionId)->value('store_id');

    $productId = (int) DB::table('products')->insertGetId([
        'store_id' => $storeId,
        'name' => 'Barang Uji',
        'sku' => 'SKU-'.uniqid(),
        'price' => $hargaJual,
        'cost_price' => $hargaModal ?? 0,
        'stock_quantity' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transaction_items')->insert([
        'transaction_id' => $transactionId,
        'product_id' => $productId,
        'product_name' => 'Barang Uji',
        'quantity' => $qty,
        'unit_price' => $hargaJual,
        'total_price' => $hargaJual * $qty,
        'unit_cost_snapshot' => $hargaModal,
        'total_cost_snapshot' => $hargaModal !== null ? $hargaModal * $qty : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('tidak menghitung pajak, service charge, dan tip sebagai pendapatan', function () {
    [$owner, $store] = makeOwnerOrg('Pajak');

    // Uang masuk laci 1.330.000, tapi pendapatan usaha hanya 1.000.000.
    buatTransaksi($store, [
        'subtotal' => 1_000_000,
        'pajak' => 110_000,
        'servis' => 100_000,
        'tip' => 120_000,
        'tanggal' => '2026-10-10 10:00:00',
    ]);

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    expect($hasil['totals']['net_revenue'])->toBe(1_000_000.0)
        // Pajak + servis + tip dilaporkan terpisah, bukan dibuang diam-diam.
        ->and($hasil['totals']['collected_not_revenue'])->toBe(330_000.0);
});

it('mengurangkan diskon dari penjualan bersih', function () {
    [$owner, $store] = makeOwnerOrg('Diskon');

    buatTransaksi($store, [
        'subtotal' => 500_000,
        'diskon' => 50_000,
        'tanggal' => '2026-10-10 10:00:00',
    ]);

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    expect($hasil['totals']['net_revenue'])->toBe(450_000.0);
});

it('mengurangkan retur penjualan dari pendapatan', function () {
    [$owner, $store] = makeOwnerOrg('Retur');

    buatTransaksi($store, ['subtotal' => 1_000_000, 'tanggal' => '2026-10-10 10:00:00']);

    DB::table('sales_returns')->insert([
        'store_id' => $store->id,
        'return_number' => 'RTN-'.uniqid(),
        'return_type' => 'refund',
        'subtotal' => 200_000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 200_000,
        'returned_at' => '2026-10-12 10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    expect($hasil['totals']['net_revenue'])->toBe(800_000.0)
        ->and($hasil['totals']['returns'])->toBe(200_000.0);
});

it('menghitung laba kotor dan margin dari harga modal', function () {
    [$owner, $store] = makeOwnerOrg('Margin');

    $trx = buatTransaksi($store, ['subtotal' => 1_000_000, 'tanggal' => '2026-10-10 10:00:00']);
    buatItem($trx, 1_000_000, 600_000);

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    expect($hasil['totals']['cogs'])->toBe(600_000.0)
        ->and($hasil['totals']['gross_profit'])->toBe(400_000.0)
        ->and($hasil['totals']['margin'])->toBe(40.0)
        ->and($hasil['totals']['cogs_coverage'])->toBe(100.0);
});

it('melaporkan cakupan HPP rendah saat sebagian produk tanpa harga modal', function () {
    [$owner, $store] = makeOwnerOrg('Cakupan');

    $trx = buatTransaksi($store, ['subtotal' => 1_000_000, 'tanggal' => '2026-10-10 10:00:00']);
    buatItem($trx, 400_000, 240_000);  // ada harga modal
    buatItem($trx, 600_000, null);     // tanpa harga modal

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    // Cakupan berbobot omzet: 400rb dari 1jt = 40%.
    expect($hasil['totals']['cogs_coverage'])->toBe(40.0)
        // Margin tetap dihitung, tapi angkanya harus dibaca bersama cakupan.
        ->and($hasil['totals']['cogs'])->toBe(240_000.0);
});

it('margin null saat tidak ada penjualan sehingga tidak membagi nol', function () {
    [$owner] = makeOwnerOrg('Kosong');

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');

    expect($hasil['totals']['net_revenue'])->toBe(0)
        ->and($hasil['totals']['margin'])->toBeNull();
});

it('ringkasan per outlet ikut memuat laba dan cakupan', function () {
    [$owner, $store] = makeOwnerOrg('PerOutlet');

    $trx = buatTransaksi($store, ['subtotal' => 800_000, 'pajak' => 88_000, 'tanggal' => '2026-10-10 10:00:00']);
    buatItem($trx, 800_000, 500_000);

    $hasil = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-10-01', '2026-10-31');
    $outlet = $hasil['summary'][$store->id];

    expect($outlet['total'])->toBe(800_000.0)
        ->and($outlet['cogs'])->toBe(500_000.0)
        ->and($outlet['gross_profit'])->toBe(300_000.0)
        ->and($outlet['margin'])->toBe(37.5)
        ->and($outlet['cogs_coverage'])->toBe(100.0);
});
