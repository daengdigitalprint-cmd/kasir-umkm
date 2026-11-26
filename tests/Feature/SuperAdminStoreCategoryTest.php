<?php

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

test('tiap toko masuk tepat satu kategori langganan dan jumlahnya sama dengan total toko', function () {
    $stores = tokoBerbagaiKeadaanLangganan();

    $stats = propsDashboardSuperAdmin($this)['stats'];

    expect($stats['premium_stores'])->toBe(3)
        ->and($stats['trial_stores'])->toBe(2)
        ->and($stats['free_stores'])->toBe(7)
        ->and($stats['premium_stores'] + $stats['trial_stores'] + $stats['free_stores'])->toBe($stats['total_stores'])
        ->and($stats['total_stores'])->toBe(count($stores));

    foreach ($stores as $label => ['store' => $store, 'category' => $expected]) {
        $matches = collect(StoreSubscriptionCategory::ALL)
            ->filter(fn (string $category) => StoreSubscriptionCategory::apply(Store::query(), $category)->whereKey($store->id)->exists())
            ->values()
            ->all();

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
    expect($stats['active_stores'])->toBe(11)
        // Paket berbayar habis, trial lewat, dan langganan dimatikan; bukan Starter gratis.
        ->and($stats['free_lapsed_stores'])->toBe(3);
});

test('kartu ringkasan menyaring daftar toko sesuai kategorinya', function () {
    $stores = collect(tokoBerbagaiKeadaanLangganan());
    $idsFor = fn (string $category) => $stores->where('category', $category)
        ->map(fn (array $row) => $row['store']->id)->sort()->values()->all();

    foreach (StoreSubscriptionCategory::ALL as $category) {
        $props = propsDashboardSuperAdmin($this, "?filter={$category}");

        expect($props['storeFilter'])->toBe($category)
            ->and(collect($props['stores']['data'])->pluck('id')->sort()->values()->all())->toBe($idsFor($category));
    }

    $aktif = propsDashboardSuperAdmin($this, '?filter=aktif');
    expect($aktif['stores']['total'])->toBe(11)
        ->and(collect($aktif['stores']['data'])->every(fn (array $row) => $row['is_active'] === true))->toBeTrue()
        ->and($aktif['stores']['next_page_url'])->toContain('filter=aktif');

    $tanpaSaringan = propsDashboardSuperAdmin($this, '?filter=ngawur');
    expect($tanpaSaringan['storeFilter'])->toBeNull()
        ->and($tanpaSaringan['stores']['total'])->toBe(12);
});
