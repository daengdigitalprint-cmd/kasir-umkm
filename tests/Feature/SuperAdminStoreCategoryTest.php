<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\StorePlanLimits;
use App\Support\StoreSubscriptionCategory;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Satu toko untuk tiap keadaan langganan yang mungkin, berikut kategori yang diharapkan.
 *
 * @return array<string, array{store: Store, category: string}>
 */
function tokoBerbagaiKeadaanLangganan(): array
{
    $plan = fn (string $slug, int $price, int $days) => SubscriptionPlan::create([
        'name' => $slug,
        'slug' => $slug,
        'price' => $price,
        'duration_days' => $days,
        'is_active' => true,
    ]);

    $starter = $plan('starter', 0, 0);
    $growup = $plan('bisnis', 99000, 30);
    $growupYearly = $plan('bisnis-tahunan', 990000, 365);

    $cases = [
        'premium bulanan' => [StoreSubscriptionCategory::PREMIUM, [
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->addDays(20),
        ]],
        'premium tahunan, dulu pernah trial' => [StoreSubscriptionCategory::PREMIUM, [
            'subscription_plan_id' => $growupYearly->id,
            'subscription_end' => now()->addDays(300),
            'pro_trial_used' => true,
            'pro_trial_active' => false,
            'pro_trial_end' => now()->subDays(40),
        ]],
        'toko lama tanpa paket dan tanpa batas waktu' => [StoreSubscriptionCategory::PREMIUM, [
            'subscription_plan_id' => null,
            'subscription_end' => null,
        ]],
        'trial berjalan' => [StoreSubscriptionCategory::TRIAL, [
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->addDays(5),
            'pro_trial_used' => true,
            'pro_trial_active' => true,
            'pro_trial_end' => now()->addDays(5),
        ]],
        'trial berjalan, toko dinonaktifkan' => [StoreSubscriptionCategory::TRIAL, [
            'is_active' => false,
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->addDays(2),
            'pro_trial_used' => true,
            'pro_trial_active' => true,
            'pro_trial_end' => now()->addDays(2),
        ]],
        'starter gratis' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $starter->id,
            'subscription_end' => null,
        ]],
        'starter gratis dengan tanggal lama yang sudah lewat' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $starter->id,
            'subscription_end' => now()->subDays(90),
        ]],
        'starter dengan bendera trial tanpa tanggal' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $starter->id,
            'subscription_end' => null,
            'pro_trial_active' => true,
            'pro_trial_end' => null,
        ]],
        'paket berbayar sudah habis' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->subDays(3),
        ]],
        'trial sudah lewat tapi belum diturunkan penjadwal' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->subDay(),
            'pro_trial_used' => true,
            'pro_trial_active' => true,
            'pro_trial_end' => now()->subDay(),
        ]],
        'paket berbayar tapi langganan dimatikan' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => $growup->id,
            'subscription_end' => now()->addDays(10),
            'is_subscription_active' => false,
        ]],
        'tanpa paket tapi berbatas waktu' => [StoreSubscriptionCategory::FREE, [
            'subscription_plan_id' => null,
            'subscription_end' => now()->addDays(10),
        ]],
    ];

    $stores = [];
    $no = 0;
    foreach ($cases as $label => [$category, $attributes]) {
        $no++;
        $stores[$label] = [
            'category' => $category,
            'store' => Store::create(array_merge([
                'name' => "Toko {$no}",
                'slug' => "toko-{$no}",
                'is_active' => true,
                'is_subscription_active' => true,
            ], $attributes)),
        ];
    }

    return $stores;
}

function propsDashboardSuperAdmin($test, string $query = ''): array
{
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $response = $test->actingAs($superAdmin)->get(route('superadmin.dashboard').$query);
    $response->assertOk();

    return $response->viewData('page')['props'];
}

/** Id semua toko di halaman daftar (daftar dikelompokkan per grup usaha), terurut. */
function idTokoDiDaftar(array $props): array
{
    return collect($props['storeGroups']['data'])->flatMap(fn (array $group) => $group['stores'])
        ->pluck('id')->sort()->values()->all();
}

test('toko tanpa grup usaha dihitung sendiri dan masuk tepat satu kategori', function () {
    $stores = tokoBerbagaiKeadaanLangganan();

    $stats = propsDashboardSuperAdmin($this)['stats'];

    expect($stats['premium_groups'])->toBe(3)
        ->and($stats['trial_groups'])->toBe(2)
        ->and($stats['free_groups'])->toBe(7)
        ->and($stats['premium_groups'] + $stats['trial_groups'] + $stats['free_groups'])->toBe($stats['total_groups'])
        ->and($stats['total_groups'])->toBe(count($stores))
        ->and($stats['total_stores'])->toBe(count($stores));

    $idsPerKategori = collect(StoreSubscriptionCategory::ALL)
        ->mapWithKeys(fn (string $category) => [$category => idTokoDiDaftar(propsDashboardSuperAdmin($this, "?filter={$category}"))]);

    foreach ($stores as $label => ['store' => $store, 'category' => $expected]) {
        $matches = $idsPerKategori->filter(fn (array $ids) => in_array($store->id, $ids, true))->keys()->all();

        expect($matches)->toBe([$expected], "Toko \"{$label}\" seharusnya hanya masuk kategori {$expected}.");
    }
});

test('kategori mengikuti definisi trial dan paket berbayar yang sudah dipakai aplikasi', function () {
    foreach (tokoBerbagaiKeadaanLangganan() as $label => ['store' => $store, 'category' => $category]) {
        $store = $store->fresh();

        // Trial berjalan = definisi "active" di TrialService::payload().
        $trialRunning = $store->pro_trial_active && $store->pro_trial_end?->isFuture();
        // Premium = lolos gerbang fitur GrowUp (StorePlanLimits) di luar masa trial.
        $fromAppRules = match (true) {
            (bool) $trialRunning => StoreSubscriptionCategory::TRIAL,
            StorePlanLimits::allowsAdvancedRetailFeatures($store) => StoreSubscriptionCategory::PREMIUM,
            default => StoreSubscriptionCategory::FREE,
        };

        expect($fromAppRules)->toBe($category, "Toko \"{$label}\"");
    }
});

test('toko aktif dihitung dari status toko dan keterangan kartu Free menghitung langganan habis', function () {
    tokoBerbagaiKeadaanLangganan();

    $stats = propsDashboardSuperAdmin($this)['stats'];

    // 12 toko, satu dinonaktifkan super admin.
    expect($stats['active_groups'])->toBe(11)
        // Paket berbayar habis, trial lewat, dan langganan dimatikan; bukan Starter gratis.
        ->and($stats['free_lapsed_groups'])->toBe(3);
});

test('kartu ringkasan menyaring daftar toko sesuai kategorinya', function () {
    $stores = collect(tokoBerbagaiKeadaanLangganan());
    $idsFor = fn (string $category) => $stores->where('category', $category)
        ->map(fn (array $row) => $row['store']->id)->sort()->values()->all();

    foreach (StoreSubscriptionCategory::ALL as $category) {
        $props = propsDashboardSuperAdmin($this, "?filter={$category}");

        expect($props['storeFilter'])->toBe($category)
            ->and(idTokoDiDaftar($props))->toBe($idsFor($category))
            ->and($props['listedStores'])->toBe(count($idsFor($category)));
    }

    $aktif = propsDashboardSuperAdmin($this, '?filter=aktif');
    expect($aktif['storeGroups']['total'])->toBe(11)
        ->and(collect($aktif['storeGroups']['data'])->every(fn (array $group) => $group['active_count'] > 0))->toBeTrue()
        ->and($aktif['storeGroups']['next_page_url'])->toContain('filter=aktif');

    $tanpaSaringan = propsDashboardSuperAdmin($this, '?filter=ngawur');
    expect($tanpaSaringan['storeFilter'])->toBeNull()
        ->and($tanpaSaringan['storeGroups']['total'])->toBe(12)
        ->and($tanpaSaringan['listedStores'])->toBe(12);
});

test('toko dalam satu grup usaha dihitung satu dan grup memakai kategori tertingginya', function () {
    $starter = SubscriptionPlan::create(['name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'duration_days' => 0, 'is_active' => true]);
    $growup = SubscriptionPlan::create(['name' => 'GrowUp', 'slug' => 'bisnis', 'price' => 99000, 'duration_days' => 30, 'is_active' => true]);

    $no = 0;
    $toko = function (?Organization $org, array $attributes) use (&$no) {
        $no++;

        return Store::create(array_merge([
            'name' => "Outlet {$no}",
            'slug' => "outlet-{$no}",
            'organization_id' => $org?->id,
            'is_active' => true,
            'is_subscription_active' => true,
        ], $attributes));
    };
    $grup = fn (string $name) => Organization::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);

    $gratis = ['subscription_plan_id' => $starter->id, 'subscription_end' => null];
    $berbayar = ['subscription_plan_id' => $growup->id, 'subscription_end' => now()->addDays(20)];
    $trial = [
        'subscription_plan_id' => $growup->id,
        'subscription_end' => now()->addDays(5),
        'pro_trial_used' => true,
        'pro_trial_active' => true,
        'pro_trial_end' => now()->addDays(5),
    ];
    $habis = ['subscription_plan_id' => $growup->id, 'subscription_end' => now()->subDays(3)];

    // Grup 7 toko: satu outlet berbayar, enam gratis -> 1 Premium.
    $tujuhToko = $grup('Grup Tujuh Toko');
    $premiumIds = [$toko($tujuhToko, $berbayar)->id];
    foreach (range(1, 6) as $i) {
        $premiumIds[] = $toko($tujuhToko, $gratis)->id;
    }

    // Grup 3 toko: satu trial, dua gratis -> 1 Trial Premium.
    $tigaToko = $grup('Grup Tiga Toko');
    $trialIds = [$toko($tigaToko, $trial)->id, $toko($tigaToko, $gratis)->id, $toko($tigaToko, $gratis)->id];

    // Grup 2 toko: satu langganan habis, satu gratis -> 1 Free (terhitung langganan habis).
    $duaToko = $grup('Grup Langganan Habis');
    $toko($duaToko, $habis);
    $toko($duaToko, $gratis);

    // Grup dengan satu toko dinonaktifkan tetap aktif; grup yang semua tokonya nonaktif tidak.
    $separuhAktif = $grup('Grup Separuh Aktif');
    $toko($separuhAktif, $gratis);
    $toko($separuhAktif, array_merge($gratis, ['is_active' => false]));
    $nonaktif = $grup('Grup Nonaktif');
    $toko($nonaktif, array_merge($gratis, ['is_active' => false]));
    $toko($nonaktif, array_merge($gratis, ['is_active' => false]));

    // Toko tanpa grup usaha dihitung sendiri.
    $toko(null, $gratis);

    $props = propsDashboardSuperAdmin($this);
    $stats = $props['stats'];

    expect($stats['total_stores'])->toBe(17)
        ->and($stats['total_groups'])->toBe(6)
        ->and($stats['premium_groups'])->toBe(1)
        ->and($stats['trial_groups'])->toBe(1)
        ->and($stats['free_groups'])->toBe(4)
        ->and($stats['free_lapsed_groups'])->toBe(1)
        ->and($stats['active_groups'])->toBe(5);

    // Daftar tanpa saringan: satu baris per grup usaha, bukan per toko.
    expect($props['storeGroups']['total'])->toBe(6)
        ->and($props['listedStores'])->toBe(17);

    // Grup 7 toko tampil sebagai SATU baris yang memuat ketujuh tokonya.
    $premium = propsDashboardSuperAdmin($this, '?filter=premium');
    expect($premium['storeGroups']['data'])->toHaveCount(1)
        ->and($premium['storeGroups']['data'][0])->toMatchArray([
            'name' => 'Grup Tujuh Toko',
            'category' => 'premium',
            'stores_count' => 7,
            'active_count' => 7,
        ])
        ->and(idTokoDiDaftar($premium))->toBe($premiumIds)
        ->and($premium['listedStores'])->toBe(7);

    $trial = propsDashboardSuperAdmin($this, '?filter=trial');
    expect($trial['storeGroups']['data'])->toHaveCount(1)
        ->and(idTokoDiDaftar($trial))->toBe($trialIds);

    $free = propsDashboardSuperAdmin($this, '?filter=free');
    expect($free['storeGroups']['total'])->toBe(4)
        ->and($free['listedStores'])->toBe(7);

    // Toko tanpa grup usaha tampil sebagai baris sendiri tanpa nama grup.
    $tanpaGrup = collect($free['storeGroups']['data'])->firstWhere('name', null);
    expect($tanpaGrup['stores'])->toHaveCount(1)
        ->and($tanpaGrup['stores_count'])->toBe(1);

    // Saringan "aktif" menampilkan grup yang punya toko aktif, lengkap dengan toko nonaktifnya.
    $aktif = propsDashboardSuperAdmin($this, '?filter=aktif');
    $separuh = collect($aktif['storeGroups']['data'])->firstWhere('name', 'Grup Separuh Aktif');
    expect($aktif['storeGroups']['total'])->toBe(5)
        ->and($separuh)->toMatchArray(['stores_count' => 2, 'active_count' => 1])
        ->and($separuh['stores'])->toHaveCount(2)
        ->and(collect($aktif['storeGroups']['data'])->pluck('name'))->not->toContain('Grup Nonaktif');
});
