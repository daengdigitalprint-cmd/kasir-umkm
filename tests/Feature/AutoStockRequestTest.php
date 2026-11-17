<?php

/*
 * Order otomatis ke Central Kitchen: Daftar Belanja outlet dikirim sebagai
 * Permintaan Stok sekali sehari pada jam yang diatur.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\AutoStockRequest;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([\App\Events\StockRequestUpdated::class]);
});

function autoStore(Organization $org, string $name, string $type, array $settings = [], string $role = 'admin'): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'auto-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => $type,
        'timezone' => 'Asia/Makassar',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return [$store, $user];
}

function autoProduct(Store $store, string $sku, float $stock, float $min): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => "Bahan {$sku}",
        'sku' => $sku,
        'price' => 0,
        'cost_price' => 100,
        'average_cost' => 100,
        'stock_quantity' => $stock,
        'min_stock_level' => $min,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

/** CK + outlet; outlet punya 2 barang menipis (A, B) dan 1 aman (C). */
function autoGroup(array $outletSettings = ['auto_stock_request' => ['enabled' => true, 'time' => '21:00']], bool $module = true): array
{
    $org = Organization::query()->create([
        'name' => 'Grup Otomatis',
        'slug' => 'otomatis-'.uniqid(),
        'central_kitchen_until' => $module ? now()->addMonth() : null,
    ]);
    [$ck, $ckAdmin] = autoStore($org, 'CK', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $admin] = autoStore($org, 'Outlet', StoreLocationType::OUTLET, $outletSettings);

    foreach (['A', 'B', 'C'] as $sku) {
        autoProduct($ck, $sku, 1000, 0);
    }
    $a = autoProduct($outlet, 'A', 2, 10);   // saran 18
    $b = autoProduct($outlet, 'B', 0, 5);    // saran 10
    autoProduct($outlet, 'C', 50, 10);       // aman

    return compact('org', 'ck', 'ckAdmin', 'outlet', 'admin', 'a', 'b');
}

/** Jam 21:30 waktu Makassar (13:30 UTC). */
function afterSendTime(): Carbon
{
    return Carbon::parse('2026-11-18 13:30:00', 'UTC');
}

it('membuat satu permintaan otomatis dari Daftar Belanja setelah jam kirim', function () {
    $g = autoGroup();
    $this->travelTo(afterSendTime());

    $this->artisan('stock-requests:auto')->assertSuccessful();

    $request = StockRequest::query()->sole();
    expect($request->is_automatic)->toBeTrue()
        ->and($request->requested_by)->toBeNull()
        ->and($request->from_store_id)->toBe($g['outlet']->id)
        ->and($request->to_store_id)->toBe($g['ck']->id)
        ->and($request->lines()->pluck('quantity_requested', 'requester_product_id')->map(fn ($q) => (float) $q)->all())
        ->toEqual([$g['a']->id => 18.0, $g['b']->id => 10.0]);

    Event::assertDispatched(\App\Events\StockRequestUpdated::class, fn ($e) => $e->action === 'created');
});

it('hanya sekali sehari dan tidak sebelum jam kirim', function () {
    autoGroup();

    $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC')); // 20:00 Makassar
    $this->artisan('stock-requests:auto');
    expect(StockRequest::query()->count())->toBe(0);

    $this->travelTo(afterSendTime());
    $this->artisan('stock-requests:auto');
    $this->artisan('stock-requests:auto');
    expect(StockRequest::query()->count())->toBe(1);
});

it('tidak mengirim ulang barang yang masih ada di permintaan terbuka', function () {
    $g = autoGroup();
    $this->travelTo(afterSendTime());
    $this->artisan('stock-requests:auto');

    // Besok: permintaan kemarin belum diproses CK → tidak ada permintaan baru.
    $this->travelTo(afterSendTime()->addDay());
    $this->artisan('stock-requests:auto');

    expect(StockRequest::query()->count())->toBe(1)
        ->and(AutoStockRequest::settings($g['outlet']->fresh())['last_run_date'])->toBe('2026-11-19');
});

it('melewati barang yang tidak dipunyai Central Kitchen', function () {
    $g = autoGroup();
    autoProduct($g['outlet'], 'TIDAK-ADA-DI-CK', 0, 5);
    $this->travelTo(afterSendTime());

    $this->artisan('stock-requests:auto');

    expect(StockRequest::query()->sole()->lines()->count())->toBe(2);
});

it('tidak jalan bila dimatikan atau modul Central Kitchen tidak aktif', function (array $settings, bool $module) {
    autoGroup($settings, $module);
    $this->travelTo(afterSendTime());

    $this->artisan('stock-requests:auto');

    expect(StockRequest::query()->count())->toBe(0);
})->with([
    'saklar mati' => [['auto_stock_request' => ['enabled' => false, 'time' => '21:00']], true],
    'modul CK mati' => [['auto_stock_request' => ['enabled' => true, 'time' => '21:00']], false],
]);

it('admin outlet mengatur jam & saklar; kasir dan CK ditolak', function () {
    $g = autoGroup([]);
    [, $cashier] = autoStore($g['org'], 'Outlet Kasir', StoreLocationType::OUTLET, [], 'cashier');

    $this->actingAs($g['admin'])->put('/stock-requests/auto-settings', ['enabled' => true, 'time' => '20:15'])
        ->assertSessionHasNoErrors();
    expect(AutoStockRequest::settings($g['outlet']->fresh()))->toMatchArray(['enabled' => true, 'time' => '20:15']);

    $this->actingAs($g['admin'])->put('/stock-requests/auto-settings', ['enabled' => true, 'time' => '25:00'])
        ->assertSessionHasErrors('time');
    // Kasir tanpa izin menu diarahkan keluar; pengaturan tidak berubah.
    $this->actingAs($cashier)->put('/stock-requests/auto-settings', ['enabled' => false, 'time' => '20:00']);
    expect(AutoStockRequest::settings($g['outlet']->fresh())['enabled'])->toBeTrue();
    $this->actingAs($g['ckAdmin'])->put('/stock-requests/auto-settings', ['enabled' => true, 'time' => '20:00'])->assertForbidden();
});

it('tombol "Buat sekarang" membuat permintaan otomatis dan menampilkan kartunya', function () {
    $g = autoGroup([]);

    $this->actingAs($g['admin'])->get('/stock-requests?tab=outgoing')->assertOk()->assertInertia(
        fn ($page) => $page->where('autoRequest.pending_items', 2)->where('autoRequest.supplier_name', 'CK')
    );

    $this->actingAs($g['admin'])->post('/stock-requests/auto-run')->assertRedirect();
    expect(StockRequest::query()->sole()->is_automatic)->toBeTrue();

    // Semua barang sudah diminta → tidak ada yang tersisa.
    $this->actingAs($g['admin'])->post('/stock-requests/auto-run')->assertSessionHas('warning');
    expect(StockRequest::query()->count())->toBe(1);
});
