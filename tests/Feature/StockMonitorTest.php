<?php

/*
 * F7 — Pantau Stok Semua Lokasi (owner, Modul Central Kitchen).
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\MultiLocationStockService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function monitorStore(Organization $org, string $name, string $type = StoreLocationType::OUTLET): Store
{
    return Store::query()->create([
        'name' => $name,
        'slug' => 'pantau-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

function monitorUser(Store $active, array $stores, string $role): User
{
    $user = User::factory()->create([
        'store_id' => $active->id,
        'role' => $role,
        'email_verified_at' => now(),
    ]);
    foreach ($stores as $store) {
        $user->stores()->attach($store->id, ['role' => $role]);
    }

    return $user;
}

function monitorProduct(Store $store, string $name, ?string $sku, float $stock, float $min = 0): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => $sku,
        'price' => 0,
        'cost_price' => 0,
        'stock_quantity' => $stock,
        'min_stock_level' => $min,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

/** Grup: Gudang Pusat + Outlet A + Outlet B, modul CK aktif, satu owner. */
function monitorGroup(bool $moduleActive = true): array
{
    $org = Organization::query()->create([
        'name' => 'Grup Kopi',
        'slug' => 'kopi-'.uniqid(),
        'central_kitchen_until' => $moduleActive ? now()->addMonth() : null,
    ]);
    $ck = monitorStore($org, 'Gudang Pusat', StoreLocationType::CENTRAL_WAREHOUSE);
    $a = monitorStore($org, 'Outlet A');
    $b = monitorStore($org, 'Outlet B');
    $owner = monitorUser($a, [$ck, $a, $b], 'owner');

    return compact('org', 'ck', 'a', 'b', 'owner');
}

it('owner melihat semua lokasinya dengan Gudang Pusat di kolom pertama', function () {
    $g = monitorGroup();
    monitorProduct($g['ck'], 'Biji Kopi', 'KOPI-1', 5000, 1000);
    monitorProduct($g['a'], 'Biji Kopi', 'kopi-1 ', 300, 500);
    monitorProduct($g['b'], 'Biji Kopi', 'KOPI-1', 0, 500);

    $this->actingAs($g['owner'])->get('/owner/stock-monitor')->assertOk()->assertInertia(
        fn ($page) => $page->component('StockMonitor/Index')
            ->has('stores', 3)
            ->where('stores.0.id', $g['ck']->id)
            ->where('summary.products', 1)
            ->where('summary.out', 1)
            ->where('summary.low', 1)
            ->where('rows.data.0.total', 5300)
            ->where("rows.data.0.locations.{$g['a']->id}.status", 'low')
            ->where("rows.data.0.locations.{$g['b']->id}.status", 'out')
            ->where("rows.data.0.locations.{$g['ck']->id}.status", 'ok')
    );
});

it('mencocokkan produk lewat SKU lalu nama', function () {
    $g = monitorGroup();
    monitorProduct($g['ck'], 'Gula Aren Cair', 'GA-1', 10);
    monitorProduct($g['a'], 'Gula aren (outlet)', 'GA-1', 2);
    monitorProduct($g['ck'], 'Susu UHT', null, 20);
    monitorProduct($g['a'], '  susu   uht ', null, 4);
    monitorProduct($g['b'], 'Susu UHT', 'SUSU-B', 1);

    $rows = collect(app(MultiLocationStockService::class)->rows(
        app(MultiLocationStockService::class)->storesFor($g['owner'])
    ));

    $gula = $rows->firstWhere('key', 'sku:ga-1');
    $susuByName = $rows->firstWhere('key', 'name:susu uht');

    expect($rows)->toHaveCount(3)
        ->and($gula['total'])->toBe(12.0)
        ->and($susuByName['total'])->toBe(24.0)
        ->and($susuByName['locations'][$g['b']->id])->toBeNull();
});

it('filter hanya menipis/habis dan pencarian', function () {
    $g = monitorGroup();
    monitorProduct($g['a'], 'Cup Plastik', 'CUP', 10, 50);
    monitorProduct($g['a'], 'Sedotan', 'SDT', 500, 50);

    $this->actingAs($g['owner'])->get('/owner/stock-monitor?problems=1')->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.name', 'Cup Plastik'));

    $this->actingAs($g['owner'])->get('/owner/stock-monitor?search=sedot')->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.name', 'Sedotan'));
});

it('tidak menampilkan lokasi di luar grup atau yang bukan milik owner', function () {
    $g = monitorGroup();
    $otherOrg = Organization::query()->create(['name' => 'Lain', 'slug' => 'lain-'.uniqid()]);
    $foreign = monitorStore($otherOrg, 'Toko Lain');
    $notOwned = monitorStore($g['org'], 'Outlet Mitra');
    // Owner grup otomatis dapat akses ke lokasi baru; cabut aksesnya untuk menguji filter kepemilikan.
    $g['owner']->stores()->detach($notOwned->id);
    $g['owner']->stores()->attach($foreign->id, ['role' => 'owner']);
    monitorProduct($foreign, 'Rahasia', 'X-1', 99);
    monitorProduct($notOwned, 'Rahasia Mitra', 'X-2', 99);

    $this->actingAs($g['owner'])->get('/owner/stock-monitor')->assertOk()->assertInertia(
        fn ($page) => $page->has('stores', 3)->where('summary.products', 0)
    );
});

it('kasir dan manajer ditolak', function (string $role) {
    $g = monitorGroup();
    $user = monitorUser($g['a'], [$g['a']], $role);

    $this->actingAs($user)->get('/owner/stock-monitor')->assertForbidden();
    $this->actingAs($user)->get('/owner/stock-monitor/export')->assertForbidden();
})->with(['cashier', 'manager']);

it('owner tanpa modul Central Kitchen diarahkan ke dashboard', function () {
    $g = monitorGroup(moduleActive: false);

    $this->actingAs($g['owner'])->get('/owner/stock-monitor')->assertRedirect(route('subscription.index', ['tab' => 'central_kitchen']));
});

it('ekspor Excel berisi kolom per lokasi', function () {
    \Maatwebsite\Excel\Facades\Excel::fake();
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-17 10:00:00'));
    $g = monitorGroup();
    monitorProduct($g['ck'], 'Biji Kopi', 'KOPI-1', 5000, 1000);

    $this->actingAs($g['owner'])->get('/owner/stock-monitor/export')->assertOk();

    \Maatwebsite\Excel\Facades\Excel::assertDownloaded(
        'stok-semua-lokasi-20261117-100000.xlsx',
        function (\App\Exports\StockMonitorExport $export) {
            return $export->headings() === ['Produk', 'SKU', 'Satuan', 'Gudang Pusat', 'Outlet A', 'Outlet B', 'Total']
                && $export->array()[0] === ['Biji Kopi', 'KOPI-1', 'pcs', 5000.0, '-', '-', 5000.0];
        }
    );
});

it('membuat file Excel sungguhan dengan sel berwarna tanpa error', function () {
    $g = monitorGroup();
    monitorProduct($g['ck'], 'Biji Kopi', 'KOPI-1', 5000, 1000);
    monitorProduct($g['a'], 'Biji Kopi', 'KOPI-1', 100, 500);
    monitorProduct($g['b'], 'Biji Kopi', 'KOPI-1', 0, 500);

    $response = $this->actingAs($g['owner'])->get('/owner/stock-monitor/export');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');

    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    expect($sheet->getCell('E2')->getValue())->toEqual(100)
        ->and($sheet->getStyle('E2')->getFill()->getStartColor()->getRGB())->toBe('FEF08A')
        ->and($sheet->getStyle('F2')->getFill()->getStartColor()->getRGB())->toBe('FECACA');
});
