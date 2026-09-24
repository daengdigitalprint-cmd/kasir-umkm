<?php

use App\Models\Store;
use App\Models\User;
use App\Services\OwnerDashboardService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeOwnerOrg(string $prefix): array
{
    $store1 = Store::query()->create([
        'name' => "{$prefix} Outlet A",
        'slug' => 'owner-test-a-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $store2 = Store::query()->create([
        'name' => "{$prefix} Outlet B",
        'slug' => 'owner-test-b-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $owner = User::query()->create([
        'name' => "{$prefix} Owner",
        'email' => 'owner-'.uniqid().'@test.com',
        'password' => 'password',
        'role' => 'owner',
        'store_id' => $store1->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $owner->stores()->attach([$store1->id, $store2->id], ['role' => 'owner']);

    return [$owner, $store1, $store2];
}

function createTransactions(Store $store, int $count, float $amount, string $date): void
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

// ═══════════════════════════════════════════════════════
// Service
// ═══════════════════════════════════════════════════════

test('service mengembalikan data multi-outlet', function () {
    [$owner, $store1, $store2] = makeOwnerOrg('SVC1');
    createTransactions($store1, 3, 10000, '2026-07-15 10:00:00');
    createTransactions($store2, 2, 20000, '2026-07-20 10:00:00');

    $service = app(OwnerDashboardService::class);
    $data = $service->revenueData($owner, 'monthly', '2026-07-01', '2026-07-31');

    expect($data['datasets'])->toHaveCount(2);
    expect($data['summary'])->toHaveCount(2);
    expect($data['summary'][(string) $store1->id]['total'])->toBe(30000.0);
    expect($data['summary'][(string) $store2->id]['total'])->toBe(40000.0);
});

test('service menghitung pertumbuhan vs periode sebelumnya', function () {
    [$owner, $store1] = makeOwnerOrg('SVC2');
    createTransactions($store1, 2, 10000, '2026-06-10 10:00:00'); // periode lalu: 20000
    createTransactions($store1, 3, 10000, '2026-07-10 10:00:00'); // periode ini: 30000

    $service = app(OwnerDashboardService::class);
    $data = $service->revenueData($owner, 'monthly', '2026-07-01', '2026-07-31');

    $growth = $data['summary'][(string) $store1->id]['growth'];
    expect($growth)->not->toBeNull();
    expect(abs((float) $growth - 50.0) < 1.0)->toBeTrue();
});

test('service memfilter berdasarkan store_id spesifik', function () {
    [$owner, $store1, $store2] = makeOwnerOrg('SVC3');
    createTransactions($store1, 2, 5000, '2026-07-10 10:00:00');
    createTransactions($store2, 2, 15000, '2026-07-10 10:00:00');

    $service = app(OwnerDashboardService::class);
    $data = $service->revenueData($owner, 'monthly', '2026-07-01', '2026-07-31', $store1->id);

    expect($data['datasets'])->toHaveCount(1);
    expect($data['datasets'][0]['label'])->toContain('Outlet A');
    expect($data['summary'][(string) $store1->id]['total'])->toBe(10000.0);
    expect(isset($data['summary'][(string) $store2->id]))->toBeFalse();
});

test('service menyediakan data export', function () {
    [$owner, $store1, $store2] = makeOwnerOrg('SVC4');
    createTransactions($store1, 1, 5000, '2026-07-10 10:00:00');
    createTransactions($store2, 1, 8000, '2026-07-15 10:00:00');

    $service = app(OwnerDashboardService::class);
    $export = $service->exportData($owner, 'monthly', '2026-07-01', '2026-07-31');

    expect($export)->toHaveCount(2);
    expect($export[0])->toHaveKeys(['Periode', 'Outlet', 'Pendapatan']);
});

// ═══════════════════════════════════════════════════════
// HTTP Routes
// ═══════════════════════════════════════════════════════

test('halaman dashboard owner bisa diakses pemilik usaha', function () {
    [$owner] = makeOwnerOrg('HTTP1');

    $this->actingAs($owner)
        ->get('/owner/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('chartData'));
});

test('non-owner tidak bisa akses dashboard owner', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get('/owner/dashboard')
        ->assertForbidden();
});

test('endpoint revenue json mengembalikan data valid', function () {
    [$owner, $store1] = makeOwnerOrg('HTTP2');
    createTransactions($store1, 2, 10000, '2026-07-15 10:00:00');

    $response = $this->actingAs($owner)
        ->get('/owner/dashboard/revenue?period=monthly&start_date=2026-07-01&end_date=2026-07-31');

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKeys(['labels', 'datasets', 'summary']);
    expect((int) $data['summary'][(string) $store1->id]['total'])->toBe(20000);
});

test('endpoint export excel menghasilkan file xlsx', function () {
    [$owner, $store1] = makeOwnerOrg('HTTP3');
    createTransactions($store1, 1, 5000, '2026-07-10 10:00:00');

    $response = $this->actingAs($owner)
        ->get('/owner/dashboard/export?period=monthly&start_date=2026-07-01&end_date=2026-07-31');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('grafik membedakan warna tiap outlet', function () {
    [$owner, $store1, $store2] = makeOwnerOrg('COLOR1');
    createTransactions($store1, 1, 10000, '2026-07-10 10:00:00');
    createTransactions($store2, 1, 10000, '2026-07-10 10:00:00');

    $service = app(OwnerDashboardService::class);
    $data = $service->revenueData($owner, 'monthly', '2026-07-01', '2026-07-31');

    $color1 = $data['datasets'][0]['borderColor'];
    $color2 = $data['datasets'][1]['borderColor'];
    expect($color1)->not->toBe($color2);
});
