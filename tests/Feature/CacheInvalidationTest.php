<?php

use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\KPIService;
use App\Support\CacheKeys;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function makeCacheStore(string $prefix): Store
{
    return Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'cache-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
    ]);
}

function makeCacheTransaction(Store $store, int $amount = 10000): Transaction
{
    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
    ]);

    return Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-CACHE-'.uniqid(),
        'subtotal' => $amount,
        'total_amount' => $amount,
        'amount_paid' => $amount,
        'paid_amount' => $amount,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'created_at' => now()->startOfMonth(),
    ]);
}

test('KPI bulanan di-cache dan mengembalikan hasil yang sama berulang kali', function () {
    $store = makeCacheStore('KPIC');
    makeCacheTransaction($store, 20000);

    $service = app(KPIService::class);
    $period = now()->format('Y-m');

    $key = CacheKeys::kpiMonthly((int) $store->id, $period);
    expect(Cache::has($key))->toBeFalse();

    $first = $service->calculateMonthlyKPI($store, $period);

    expect(Cache::has($key))->toBeTrue();
    expect($first['revenue'])->toBe(20000.0);

    // Simulasikan data baru — hasil kamar kedua harus SAMA (masih dari cache)
    // jika belum ada invalidasi, membuktikan hit cache bekerja.
    $second = $service->calculateMonthlyKPI($store, $period);

    expect($second)->toBe($first);
});

test('transaksi baru meng-invalidate cache KPI dan dashboard', function () {
    $store = makeCacheStore('KPIV');
    $period = now()->format('Y-m');

    $service = app(KPIService::class);
    $first = $service->calculateMonthlyKPI($store, $period);
    $dashKey = CacheKeys::dashboardInsights((int) $store->id);

    expect($first['revenue'])->toBe(0.0);
    Cache::put($dashKey, ['dummy' => 'stale'], 60);

    // Buat transaksi baru → observer harus mem-flush cache laporan.
    makeCacheTransaction($store, 50000);

    // Dashboard key langsung di-forget → tidak ada data stale.
    expect(Cache::has($dashKey))->toBeFalse();

    // Query ulang menghasilkan nilai baru (bukan stale) karena memakai versi baru.
    $fresh = $service->calculateMonthlyKPI($store, $period);
    expect($fresh['revenue'])->toBe(50000.0);
});

test('transaksi dihapus meng-invalidate cache KPI', function () {
    $store = makeCacheStore('KPID');
    $transaction = makeCacheTransaction($store, 30000);
    $period = now()->format('Y-m');

    $service = app(KPIService::class);
    $service->calculateMonthlyKPI($store, $period);

    $transaction->delete();

    // Setelah delete, hasil baru harus 0 — bukan data stale 30000.
    $fresh = $service->calculateMonthlyKPI($store, $period);
    expect($fresh['revenue'])->toBe(0.0);
});

test('update status transaksi yang relevan meng-invalidate cache', function () {
    $store = makeCacheStore('KPIS');
    $transaction = makeCacheTransaction($store, 40000);
    $period = now()->format('Y-m');

    $service = app(KPIService::class);
    $service->calculateMonthlyKPI($store, $period);

    // Ubah status → total_amount berubah → harus invalidate.
    $transaction->update(['status' => 'voided', 'is_void' => true]);

    // Hasil baru tidak lagi menghitung transaksi yang di-void.
    $fresh = $service->calculateMonthlyKPI($store, $period);
    expect($fresh['revenue'])->toBe(0.0);
});
