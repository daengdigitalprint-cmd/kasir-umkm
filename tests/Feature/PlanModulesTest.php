<?php

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\User;
use App\Support\Accounting\AccountingFeatures;
use App\Support\PlanModules;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User}
 */
function makePlanStore(string $planSlug, array $settings = []): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        ['name' => $planSlug, 'price' => $planSlug === 'starter' ? 0 : 99000, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko '.$planSlug,
        'slug' => $planSlug.'-'.Str::random(6),
        'business_type' => 'fnb',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => $planSlug === 'starter' ? null : now()->addMonth(),
        'settings' => $settings,
    ]);

    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

test('paket Gratis: modul berbayar terkunci, paket GrowUp tidak', function () {
    [$free] = makePlanStore('starter');
    [$paid] = makePlanStore('bisnis');

    foreach (array_keys(PlanModules::MODULES) as $module) {
        expect(PlanModules::locked($free, $module))->toBeTrue("{$module} harus terkunci di paket Gratis");
        expect(PlanModules::locked($paid, $module))->toBeFalse("{$module} harus terbuka di paket GrowUp");
    }
});

test('paket Gratis: saklar modul berbayar dianggap mati walau tersimpan menyala', function () {
    [$free] = makePlanStore('starter', ['recipes_enabled' => true, 'accounting_enabled' => true, 'scan_meja_enabled' => true]);

    expect(PlanModules::settingOn($free, 'recipes_enabled'))->toBeFalse()
        ->and(PlanModules::settingOn($free, 'scan_meja_enabled'))->toBeFalse()
        ->and(PlanModules::settingOn($free, 'receivables_enabled'))->toBeFalse()
        ->and(PlanModules::settingOn($free, 'ai_chat_enabled'))->toBeFalse()
        ->and(AccountingFeatures::enabled($free))->toBeFalse();
});

test('paket GrowUp: saklar mengikuti nilai tersimpan dan default', function () {
    [$paid] = makePlanStore('bisnis', ['scan_meja_enabled' => true]);

    expect(PlanModules::settingOn($paid, 'scan_meja_enabled'))->toBeTrue()
        ->and(PlanModules::settingOn($paid, 'receivables_enabled'))->toBeTrue()
        ->and(PlanModules::settingOn($paid, 'ai_chat_enabled'))->toBeTrue()
        ->and(PlanModules::settingOn($paid, 'kitchen_queue_enabled'))->toBeFalse();
});

test('paket Gratis: modul yang datanya sudah berjalan tetap bisa dipakai', function () {
    [$free] = makePlanStore('starter', ['scan_meja_enabled' => true]);
    Table::query()->forceCreate(['store_id' => $free->id, 'name' => 'Meja 1', 'slug' => (string) Str::uuid()]);
    PlanModules::forgetCache($free);

    expect(PlanModules::locked($free, 'scan_meja'))->toBeFalse()
        ->and(PlanModules::settingOn($free, 'scan_meja_enabled'))->toBeTrue()
        ->and(PlanModules::locked($free, 'recipes'))->toBeTrue();
});

test('paket Gratis: izin khusus per toko membuka modul', function () {
    [$free] = makePlanStore('starter', ['plan_unlocked_modules' => ['kpi']]);

    expect(PlanModules::locked($free, 'kpi'))->toBeFalse()
        ->and(PlanModules::locked($free, 'ai_chat'))->toBeTrue();
});

test('paket Gratis: route modul berbayar dialihkan ke dashboard dengan ajakan upgrade', function (string $url) {
    [, $user] = makePlanStore('starter');

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
})->with(['/kpi', '/recipes', '/receivables', '/financial/journal', '/tables']);

test('paket GrowUp: route modul berbayar tidak dialihkan oleh kunci paket', function () {
    [, $user] = makePlanStore('bisnis');

    $this->actingAs($user)->get('/kpi')
        ->assertOk()
        ->assertSessionMissing('warning');
});

test('paket Gratis: menyalakan saklar modul berbayar di Pengaturan ditolak tanpa mengubah data', function () {
    [$free, $user] = makePlanStore('starter');

    $this->actingAs($user)->put(route('settings.store.update'), [
        'name' => $free->name,
        'business_type' => 'fnb',
        'scan_meja_enabled' => true,
        'kitchen_queue_enabled' => true,
    ])->assertSessionHas('warning');

    $free->refresh();
    expect($free->settings['scan_meja_enabled'] ?? false)->toBeFalse()
        ->and($free->settings['kitchen_queue_enabled'] ?? false)->toBeFalse();
});

test('paket Gratis: props Inertia memuat menu yang terkunci', function () {
    [, $user] = makePlanStore('starter');

    $props = $this->actingAs($user)->get('/settings/features')->viewData('page')['props'];

    expect($props['plan_locked_menus'])->toContain('kpi')
        ->and($props['plan_locked_settings'])->toContain('accounting_enabled')
        ->and($props['can_use_ai_assistant'])->toBeFalse()
        ->and($props['recipes_enabled'])->toBeFalse();
});

test('paket Gratis tidak lagi dibatasi jumlah transaksi per bulan', function () {
    $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
    $starter = SubscriptionPlan::where('slug', 'starter')->first();

    expect($starter->max_transactions)->toBeNull()
        ->and($starter->max_products)->toBe(StorePlanLimits::STARTER_MAX_PRODUCTS)
        ->and($starter->features)->toBe(StorePlanLimits::STARTER_FEATURES)
        ->and((int) SubscriptionPlan::where('slug', 'bisnis-tahunan')->value('price'))->toBe(990000);
});
