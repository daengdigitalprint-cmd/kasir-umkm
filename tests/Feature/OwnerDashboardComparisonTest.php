<?php

use App\Models\Store;
use App\Models\User;
use App\Services\OwnerDashboardService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Store, 2: Store}
 */
function cmpOwnerOrg(string $prefix): array
{
    $make = fn (string $suffix) => Store::query()->create([
        'name' => "{$prefix} Outlet {$suffix}",
        'slug' => 'cmp-'.strtolower($suffix).'-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $store1 = $make('A');
    $store2 = $make('B');

    $owner = User::query()->create([
        'name' => "{$prefix} Owner",
        'email' => 'cmp-owner-'.uniqid().'@test.com',
        'password' => 'password',
        'role' => 'owner',
        'store_id' => $store1->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $owner->stores()->attach([$store1->id, $store2->id], ['role' => 'owner']);

    return [$owner, $store1, $store2];
}

function cmpTransactions(Store $store, int $count, float $amount, string $date): void
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    for ($i = 0; $i < $count; $i++) {
        DB::table('transactions')->insert([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'transaction_number' => 'TXN-'.uniqid(),
            'status' => 'completed',
            'payment_method' => 'cash',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'amount_paid' => $amount,
            'change_amount' => 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}

test('pembanding memakai rentang sepanjang yang sama tepat sebelumnya', function () {
    [$owner, $store1] = cmpOwnerOrg('Banding');
    // 1–19 Nov (19 hari) → pembanding 13–31 Okt.
    cmpTransactions($store1, 1, 10000, '2026-10-12 10:00:00'); // di luar pembanding
    cmpTransactions($store1, 2, 10000, '2026-10-20 10:00:00'); // pembanding: 20.000
    cmpTransactions($store1, 3, 10000, '2026-11-05 10:00:00'); // sekarang: 30.000
    cmpTransactions($store1, 5, 10000, '2026-09-10 10:00:00'); // jauh di luar, tak boleh ikut

    $data = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-11-01', '2026-11-19');

    expect($data['comparison'])->toBe(['start_date' => '2026-10-13', 'end_date' => '2026-10-31'])
        ->and($data['totals']['net_revenue'])->toBe(30000.0)
        ->and($data['totals']['previous_net_revenue'])->toBe(20000.0)
        ->and(round($data['totals']['growth'], 1))->toBe(50.0)
        // Grafik hanya berisi periode di dalam rentang terpilih (bukan Sep/Okt).
        ->and($data['period_keys'])->toBe(['2026-11']);
});

test('grafik harian memuat semua hari dalam rentang termasuk yang kosong', function () {
    [$owner, $store1] = cmpOwnerOrg('Harian');
    cmpTransactions($store1, 1, 10000, '2026-11-02 10:00:00');

    $data = app(OwnerDashboardService::class)->revenueData($owner, 'daily', '2026-11-01', '2026-11-03');

    expect($data['period_keys'])->toBe(['2026-11-01', '2026-11-02', '2026-11-03'])
        ->and($data['datasets'][0]['data'])->toBe([0.0, 10000.0, 0.0]);
});

test('pertumbuhan total berbobot omzet, bukan rata-rata persen per outlet', function () {
    [$owner, $store1, $store2] = cmpOwnerOrg('Bobot');
    // Outlet A: 100.000 → 110.000 (+10%). Outlet B: 1.000 → 2.000 (+100%).
    cmpTransactions($store1, 1, 100000, '2026-10-15 10:00:00');
    cmpTransactions($store1, 1, 110000, '2026-11-15 10:00:00');
    cmpTransactions($store2, 1, 1000, '2026-10-15 10:00:00');
    cmpTransactions($store2, 1, 2000, '2026-11-15 10:00:00');

    $data = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-11-01', '2026-11-30');

    // (112.000 - 101.000) / 101.000 = 10,9% — bukan (10 + 100) / 2 = 55%.
    expect(round($data['totals']['growth'], 1))->toBe(10.9);
});

test('ringkasan memuat jumlah transaksi, rata-rata, jam dan hari tersibuk', function () {
    [$owner, $store1] = cmpOwnerOrg('Trx');
    cmpTransactions($store1, 3, 10000, '2026-11-04 12:00:00'); // Rabu jam 12
    cmpTransactions($store1, 1, 10000, '2026-11-07 08:00:00'); // Sabtu jam 08

    $t = app(OwnerDashboardService::class)->revenueData($owner, 'monthly', '2026-11-01', '2026-11-30')['totals'];

    expect($t['transactions'])->toBe(4)
        ->and($t['avg_ticket'])->toEqual(10000)
        ->and($t['busiest_hour'])->toBe(12)
        ->and($t['busiest_weekday'])->toBe(3);
});

test('gudang pusat tidak dihitung sebagai outlet penjualan', function () {
    [$owner, $store1] = cmpOwnerOrg('Gudang');
    $ck = Store::query()->create([
        'name' => 'Gudang Pusat',
        'slug' => 'ck-'.uniqid(),
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $owner->stores()->attach($ck->id, ['role' => 'owner']);
    cmpTransactions($store1, 1, 10000, '2026-11-05 10:00:00');

    $service = app(OwnerDashboardService::class);
    $data = $service->revenueData($owner, 'monthly', '2026-11-01', '2026-11-30');

    expect($data['summary'])->toHaveCount(2)
        ->and(array_keys($data['summary']))->not->toContain($ck->id)
        ->and($service->salesStores($owner)->pluck('id')->all())->not->toContain($ck->id);
});

test('halaman tanpa filter outlet mengirim store_id null dan ekspor mengikuti outlet', function () {
    [$owner, $store1, $store2] = cmpOwnerOrg('Filter');
    cmpTransactions($store1, 1, 10000, '2026-07-10 10:00:00');
    cmpTransactions($store2, 1, 20000, '2026-07-10 10:00:00');

    $this->actingAs($owner)->get('/owner/dashboard?period=monthly&start_date=2026-07-01&end_date=2026-07-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.store_id', null));

    $rows = app(OwnerDashboardService::class)->exportData($owner, 'monthly', '2026-07-01', '2026-07-31', $store2->id);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Outlet'])->toBe($store2->name);
});
