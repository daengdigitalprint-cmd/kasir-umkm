<?php

use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\NativeComponents\Lainnya\Index as LainnyaIndex;
use App\NativeComponents\Layouts\TabsLayout;
use App\Support\OptionalFeatures;
use App\Support\StoreLocationType;
use App\Support\UiMode;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function uiModeStore(array $attributes = []): Store
{
    return Store::query()->create([
        'name' => 'Toko Mode',
        'slug' => 'toko-mode-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        ...$attributes,
    ]);
}

function uiModeAdmin(Store $store, string $role = 'admin'): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

test('toko tanpa ui_mode dianggap mode lengkap', function () {
    $store = uiModeStore(['settings' => ['tax_rate' => 0]]);

    expect(UiMode::of($store))->toBe(UiMode::FULL)
        ->and(UiMode::isSimple($store))->toBeFalse()
        ->and(UiMode::of(null))->toBe(UiMode::FULL);
});

test('menu inti mengikuti jenis usaha', function () {
    $retail = uiModeStore(['business_type' => 'retail']);
    $fnb = uiModeStore(['business_type' => 'fnb']);
    $barber = uiModeStore(['business_type' => 'barbershop']);
    $laundry = uiModeStore(['business_type' => 'laundry']);

    expect(UiMode::coreMenus($retail))->toContain('pos', 'products', 'reports')
        ->not->toContain('tables', 'queues', 'stock_opnames', 'receivables')
        ->and(UiMode::coreMenus($fnb))->toContain('tables', 'table_orders')
        ->and(UiMode::coreMenus($barber))->toContain('queues')
        ->and(UiMode::coreMenus($laundry))->toContain('laundry_orders');
});

test('menu tambahan dan modul yang menyala ikut tampil di mode sederhana', function () {
    $store = uiModeStore([
        'business_type' => 'retail',
        'settings' => [
            'ui_mode' => 'simple',
            'simple_extras' => ['discounts'],
            'kitchen_queue_enabled' => true,
        ],
    ]);

    expect(UiMode::visibleMenus($store))
        ->toContain('discounts', 'kitchen_queue', 'pos')
        ->not->toContain('stock_transfers', 'receivables');
});

test('piutang yang belum lunas membuat menu piutang tetap tampil', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $user = uiModeAdmin($store);

    expect(UiMode::visibleMenus($store))->not->toContain('receivables');

    Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-DEBT-1',
        'subtotal' => 50000,
        'total_amount' => 50000,
        'amount_paid' => 0,
        'paid_amount' => 0,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'payment_status' => 'unpaid',
    ]);
    UiMode::forgetCache($store);

    expect(UiMode::visibleMenus($store))->toContain('receivables');
});

test('props inertia membawa ui_mode dan daftar menu', function () {
    $simple = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $this->actingAs(uiModeAdmin($simple));
    $props = $this->get('/settings/profile')->viewData('page')['props'];

    expect($props['ui_mode'])->toBe('simple')
        ->and($props['simple_visible_menus'])->toContain('pos')
        ->not->toContain('stock_opnames');

    $full = uiModeStore();
    $this->actingAs(uiModeAdmin($full));
    $props = $this->get('/settings/profile')->viewData('page')['props'];

    expect($props['ui_mode'])->toBe('full')
        ->and($props['simple_visible_menus'])->toBe([]);
});

test('admin dapat berpindah mode tanpa mengubah pengaturan lain', function () {
    $store = uiModeStore(['settings' => ['tax_rate' => 11, 'recipes_enabled' => true]]);
    $this->actingAs(uiModeAdmin($store));

    $this->put('/settings/ui-mode', ['ui_mode' => 'simple'])->assertSessionHasNoErrors();
    $store->refresh();
    expect($store->settings['ui_mode'])->toBe('simple')
        ->and($store->settings['tax_rate'])->toBe(11)
        ->and($store->settings['recipes_enabled'])->toBeTrue();

    $this->put('/settings/ui-mode', ['ui_mode' => 'full'])->assertSessionHasNoErrors();
    expect($store->refresh()->settings['ui_mode'])->toBe('full');

    $this->put('/settings/ui-mode', ['ui_mode' => 'ngawur'])->assertSessionHasErrors('ui_mode');
});

test('kasir tidak dapat mengubah mode', function () {
    $store = uiModeStore();
    $this->actingAs(uiModeAdmin($store, 'cashier'));

    $this->put('/settings/ui-mode', ['ui_mode' => 'simple']);

    expect(UiMode::of($store->refresh()))->toBe(UiMode::FULL);
});

test('mode sederhana tidak memblokir rute menu yang disembunyikan', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $this->actingAs(uiModeAdmin($store));

    $this->get('/stock-movements')->assertStatus(200);
});

test('toko baru mulai dari mode sederhana, outlet berikutnya mengikuti outlet acuan', function () {
    Cache::flush();
    $full = uiModeStore(['settings' => ['ui_mode' => 'full']]);
    $simple = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $legacy = uiModeStore();

    expect(UiMode::defaultForNewStore(null))->toBe(UiMode::SIMPLE)
        ->and(UiMode::defaultForNewStore($full))->toBe(UiMode::FULL)
        ->and(UiMode::defaultForNewStore($simple))->toBe(UiMode::SIMPLE)
        ->and(UiMode::defaultForNewStore($legacy))->toBe(UiMode::FULL);
});

// ── Fitur Tambahan (Tahap 4) ─────────────────────────────

test('mengaktifkan fitur di mode sederhana menampilkan menunya', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $this->actingAs(uiModeAdmin($store));

    expect(UiMode::visibleMenus($store))->not->toContain('stock_movements');

    $this->put('/settings/features/stock_history', ['enabled' => true])->assertSessionHas('success');
    $store->refresh();

    expect(UiMode::visibleMenus($store))->toContain('stock_movements')
        ->and(OptionalFeatures::isActive($store, 'stock_history'))->toBeTrue();

    $this->put('/settings/features/stock_history', ['enabled' => false])->assertSessionHas('success');
    expect(UiMode::visibleMenus($store->refresh()))->not->toContain('stock_movements');
});

test('fitur dengan saklar modul ikut menyalakan dan mematikan saklarnya', function () {
    $store = uiModeStore(['business_type' => 'retail', 'settings' => ['ui_mode' => 'simple']]);
    $this->actingAs(uiModeAdmin($store));

    $this->put('/settings/features/kitchen_queue', ['enabled' => true]);
    $store->refresh();
    expect($store->settings['kitchen_queue_enabled'])->toBeTrue()
        ->and(UiMode::visibleMenus($store))->toContain('kitchen_queue');

    $this->put('/settings/features/kitchen_queue', ['enabled' => false]);
    $store->refresh();
    expect($store->settings['kitchen_queue_enabled'])->toBeFalse()
        ->and(UiMode::visibleMenus($store))->not->toContain('kitchen_queue')
        ->and($store->settings['ui_mode'])->toBe('simple');
});

test('fitur paket berbayar ditolak bila paket tidak mendukung', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple'], 'is_subscription_active' => false]);
    $this->actingAs(uiModeAdmin($store));

    expect(OptionalFeatures::needsUpgrade($store, 'discounts'))->toBeTrue();

    $this->put('/settings/features/discounts', ['enabled' => true])->assertSessionHas('error');
    expect(UiMode::visibleMenus($store->refresh()))->not->toContain('discounts');
});

test('fitur yang datanya masih berjalan tidak bisa dimatikan', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $user = uiModeAdmin($store);
    $this->actingAs($user);

    Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-DEBT-2',
        'subtotal' => 50000,
        'total_amount' => 50000,
        'amount_paid' => 0,
        'paid_amount' => 0,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'payment_status' => 'unpaid',
    ]);
    UiMode::forgetCache($store);

    $this->put('/settings/features/receivables', ['enabled' => false])->assertSessionHas('error');
    expect(OptionalFeatures::settingOn($store->refresh(), 'receivables_enabled'))->toBeTrue();
});

test('halaman fitur tambahan tampil untuk admin dan ditolak untuk kasir', function () {
    $store = uiModeStore(['settings' => ['ui_mode' => 'simple']]);

    $this->actingAs(uiModeAdmin($store));
    $props = $this->get('/settings/features')->assertStatus(200)->viewData('page')['props'];
    expect($props['uiMode'])->toBe('simple')
        ->and(collect($props['groups'])->flatMap(fn ($g) => $g['features'])->count())->toBe(count(OptionalFeatures::FEATURES));

    $this->actingAs(uiModeAdmin($store, 'cashier'));
    $this->get('/settings/features')->assertRedirect();
    $this->put('/settings/features/nope', ['enabled' => true])->assertRedirect();
});

test('fitur tidak dikenal menghasilkan 404 dan mode lengkap tidak mengubah fitur tanpa saklar', function () {
    $store = uiModeStore();
    $this->actingAs(uiModeAdmin($store));

    $this->put('/settings/features/nope', ['enabled' => true])->assertNotFound();

    $this->put('/settings/features/stock_history', ['enabled' => false])->assertSessionHas('info');
    expect(OptionalFeatures::isActive($store->refresh(), 'stock_history'))->toBeTrue();
});

// ── Aplikasi mobile (Tahap 6) ────────────────────────────

test('menu lainnya di mobile menyembunyikan menu lanjutan saat mode sederhana', function () {
    $simple = uiModeStore(['settings' => ['ui_mode' => 'simple']]);
    $this->actingAs(uiModeAdmin($simple));

    Native::test(LainnyaIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->assertSet('simpleMode', true)
        ->assertSet('showKas', false)
        ->assertSee('Manajemen Pelanggan')
        ->assertDontSee('Kas Masuk / Keluar')
        ->assertDontSee('Penggajian');

    $simple->update(['settings' => ['ui_mode' => 'simple', 'simple_extras' => ['cash_transactions']]]);
    $this->actingAs(uiModeAdmin($simple->refresh()));

    Native::test(LainnyaIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->assertSet('showKas', true)
        ->assertSee('Kas Masuk / Keluar');
});

test('menu lainnya di mobile tidak berubah saat mode lengkap', function () {
    $this->actingAs(uiModeAdmin(uiModeStore()));

    Native::test(LainnyaIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->assertSet('simpleMode', false)
        ->assertSee('Kas Masuk / Keluar')
        ->assertSee('Absensi Karyawan')
        ->assertSee('Penggajian');
});
