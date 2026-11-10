<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSaleConsumption;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductImportService;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\ProductImportSchema;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function makeImportOrg(string $prefix, array $storeAttributes = []): array
{
    $store = Store::query()->create(array_merge([
        'name' => "{$prefix} Store",
        'slug' => 'import-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ], $storeAttributes));

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);

    $store->members()->attach($admin->id, ['role' => 'admin']);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $admin, $cashier];
}

function makeImportProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Es Teh Manis',
        'sku' => 'MIN-001',
        'price' => 5000,
        'cost_price' => 2000,
        'average_cost' => 2000,
        'stock_quantity' => 10,
        'min_stock_level' => 2,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ], $attributes));
}

/**
 * Baris impor sesuai urutan kolom skema untuk toko tersebut.
 */
function importRow(Store $store, array $values): array
{
    $row = [];
    foreach (ProductImportSchema::keysFor($store) as $key) {
        $row[] = $values[$key] ?? null;
    }

    return $row;
}

function importRows(Store $store, array $rowsOfValues): array
{
    $rows = [ProductImportSchema::headingsFor($store)];
    foreach ($rowsOfValues as $values) {
        $rows[] = importRow($store, $values);
    }

    return $rows;
}

/** Header sheet "Komponen Produk" sesuai ekspor produk + komponen. */
function componentSheetHeader(): array
{
    return [
        'Tipe Komponen', 'ID Produk', 'Nama Produk', 'SKU Produk', 'SKU Varian Produk', 'Nama Varian Produk',
        'ID Komponen', 'Nama Komponen', 'SKU Komponen', 'SKU Varian Komponen', 'Nama Varian Komponen',
        'Kuantitas', 'Satuan Dasar', 'Urutan', 'Kode Resep',
    ];
}

/** Header sheet "Varian Produk" sesuai ekspor produk + komponen. */
function variantSheetHeader(): array
{
    return [
        'ID Produk', 'Nama Produk', 'SKU Produk', 'Nama Varian', 'SKU Varian', 'Barcode Varian',
        'Harga', 'Harga Grosir', 'Harga Beli', 'Stok', 'Stok Minimum',
        'Izinkan Penjualan Stok Habis', 'Status Aktif', 'Urutan',
    ];
}

/** Tulis baris impor menjadi file .xlsx sementara. */
function writeImportFile(array $rows): string
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/** Salin isi respons unduhan Excel ke file sementara. */
function downloadToFile(\Illuminate\Testing\TestResponse $response, string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix).'.xlsx';
    $base = $response->baseResponse;

    if ($base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        copy($base->getFile()->getPathname(), $path);
    } else {
        file_put_contents($path, $response->streamedContent());
    }

    return $path;
}

// ═══════════════════════════════════════════════════════
// Ekspor produk
// ═══════════════════════════════════════════════════════

test('ekspor produk mengunduh file excel', function () {
    [$store, $admin] = makeImportOrg('EX1');
    makeImportProduct($store);

    $response = $this->actingAs($admin)->get('/products/export');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('ekspor produk memuat data produk yang akurat', function () {
    [$store, $admin] = makeImportOrg('EX2');
    makeImportProduct($store, ['name' => 'Kopi Susu', 'sku' => 'MIN-009', 'price' => 18000]);

    $this->actingAs($admin);

    $path = downloadToFile($this->get('/products/export'), 'export');

    $sheets = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Imports\ProductsImport, $path);
    $rows = $sheets[0];

    expect($rows[0])->toBe(ProductImportSchema::headingsFor($store, withExportInfo: true));

    $nameIndex = array_search(ProductImportSchema::COL_NAME, ProductImportSchema::keysFor($store), true);
    $priceIndex = array_search(ProductImportSchema::COL_PRICE, ProductImportSchema::keysFor($store), true);

    expect($rows[1][$nameIndex])->toBe('Kopi Susu');
    expect((float) $rows[1][$priceIndex])->toBe(18000.0);

    @unlink($path);
});

test('kasir tidak dapat mengekspor produk', function () {
    [$store, , $cashier] = makeImportOrg('EX3');
    makeImportProduct($store);

    $this->actingAs($cashier)
        ->get('/products/export')
        ->assertRedirect(route('dashboard'));
});

// ═══════════════════════════════════════════════════════
// Template impor
// ═══════════════════════════════════════════════════════

test('template impor berisi sheet isian dan sheet panduan', function () {
    [$store, $admin] = makeImportOrg('TPL1');

    $this->actingAs($admin);

    $path = downloadToFile($this->get('/products/import/template'), 'template');

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

    expect($spreadsheet->getSheetCount())->toBe(2);
    expect($spreadsheet->getSheet(0)->getTitle())->toBe('Template Produk');
    expect($spreadsheet->getSheet(1)->getTitle())->toBe('Panduan Pengisian');

    $headings = $spreadsheet->getSheet(0)->rangeToArray('A1:R1')[0];
    expect($headings[0])->toBe('Nama Produk*');

    // Sheet isian berisi header + 3 baris contoh (CONTOH_).
    expect($spreadsheet->getSheet(0)->getHighestDataRow())->toBe(4);

    $guide = $spreadsheet->getSheet(1)->toArray();
    $guideText = implode(' ', array_map(fn ($row) => implode(' ', array_map('strval', $row)), $guide));
    expect($guideText)->toContain('ATURAN UMUM');
    expect($guideText)->toContain('PENJELASAN & CONTOH KOLOM');

    @unlink($path);
});

test('template impor dapat diunggah kembali sebagai file impor kosong', function () {
    [$store, $admin] = makeImportOrg('TPL2');

    $this->actingAs($admin);

    $path = downloadToFile($this->get('/products/import/template'), 'template');

    $rows = (new \App\Imports\ProductsImport)->rowsFrom(
        new UploadedFile($path, 'template.xlsx', null, null, true)
    );

    // Sheet produk terdeteksi walaupun ada sheet panduan.
    expect($rows)->not->toBeEmpty();
    expect(ProductImportSchema::normalizeHeader($rows[0][0]))->toBe(ProductImportSchema::COL_NAME);

    @unlink($path);
});

// ═══════════════════════════════════════════════════════
// Impor produk — jalur sukses
// ═══════════════════════════════════════════════════════

test('impor menambahkan produk baru beserta kategori dan riwayat stok', function () {
    [$store, $admin] = makeImportOrg('IMP1');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Es Teh Manis',
            ProductImportSchema::COL_SKU => 'MIN-001',
            ProductImportSchema::COL_CATEGORY => 'Minuman',
            ProductImportSchema::COL_UNIT => 'cup',
            ProductImportSchema::COL_PRICE => '5.000',
            ProductImportSchema::COL_COST => '2000',
            ProductImportSchema::COL_STOCK => '24',
            ProductImportSchema::COL_MIN_STOCK => '5',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(1);
    expect($result['updated'])->toBe(0);

    $product = $store->products()->where('sku', 'MIN-001')->first();
    expect($product)->not->toBeNull();
    expect((float) $product->price)->toBe(5000.0);
    expect((float) $product->stock_quantity)->toBe(24.0);
    expect($product->category)->toBe('Minuman');
    expect($product->product_category_id)->not->toBeNull();
    expect($product->is_active)->toBeTrue();
    expect($product->track_stock)->toBeTrue();

    expect(ProductCategory::where('store_id', $store->id)->where('name', 'Minuman')->exists())->toBeTrue();

    $movement = StockMovement::where('product_id', $product->id)->first();
    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity_change)->toBe(24.0);
    expect($movement->type)->toBe(StockMovement::TYPE_ADJUSTMENT);
});

test('impor memperbarui produk lama berdasarkan sku tanpa menghapus data lain', function () {
    [$store, $admin] = makeImportOrg('IMP2');
    $existing = makeImportProduct($store, ['description' => 'Catatan lama', 'brand' => 'Merek Lama']);
    $other = makeImportProduct($store, ['name' => 'Kopi Hitam', 'sku' => 'MIN-002', 'price' => 12000]);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Es Teh Manis Jumbo',
            ProductImportSchema::COL_SKU => 'MIN-001',
            ProductImportSchema::COL_PRICE => '7000',
            ProductImportSchema::COL_STOCK => '15',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(0);
    expect($result['updated'])->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('Es Teh Manis Jumbo');
    expect((float) $existing->price)->toBe(7000.0);
    expect((float) $existing->stock_quantity)->toBe(15.0);
    // Kolom kosong mempertahankan nilai lama.
    expect($existing->description)->toBe('Catatan lama');
    expect($existing->brand)->toBe('Merek Lama');
    expect((float) $existing->cost_price)->toBe(2000.0);

    // Produk lain tidak tersentuh.
    $other->refresh();
    expect($other->name)->toBe('Kopi Hitam');
    expect((float) $other->price)->toBe(12000.0);

    $movement = StockMovement::where('product_id', $existing->id)->latest('id')->first();
    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity_change)->toBe(5.0);
});

test('impor lewat http menampilkan notifikasi sukses', function () {
    [$store, $admin] = makeImportOrg('IMP3');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Roti Bakar',
            ProductImportSchema::COL_PRICE => '15000',
            ProductImportSchema::COL_STOCK => '10',
        ],
    ]);

    $path = writeImportFile($rows);

    $response = $this->actingAs($admin)->post('/products/import', [
        'file' => new UploadedFile($path, 'impor.xlsx', null, null, true),
    ]);

    $response->assertRedirect(route('products.index'));
    $response->assertSessionHas('success');
    expect(session('success'))->toContain('1 produk baru');
    expect($store->products()->where('name', 'Roti Bakar')->exists())->toBeTrue();

    @unlink($path);
});

test('file hasil ekspor dapat diimpor kembali tanpa mengubah jumlah produk', function () {
    [$store, $admin] = makeImportOrg('RT1');
    makeImportProduct($store);
    makeImportProduct($store, ['name' => 'Kopi Hitam', 'sku' => 'MIN-002', 'price' => 12000, 'stock_quantity' => 4]);

    $this->actingAs($admin);

    $exportPath = downloadToFile($this->get('/products/export'), 'roundtrip');

    $rows = (new \App\Imports\ProductsImport)->rowsFrom(
        new UploadedFile($exportPath, 'produk.xlsx', null, null, true)
    );

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(0);
    expect($result['updated'])->toBe(2);
    expect($store->products()->count())->toBe(2);

    @unlink($exportPath);
});

// ═══════════════════════════════════════════════════════
// Impor produk — validasi
// ═══════════════════════════════════════════════════════

test('impor menolak baris tanpa nama produk dan tidak menyimpan apa pun', function () {
    [$store, $admin] = makeImportOrg('VAL1');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk Valid',
            ProductImportSchema::COL_PRICE => '10000',
        ],
        [
            ProductImportSchema::COL_SKU => 'TANPA-NAMA',
            ProductImportSchema::COL_PRICE => '10000',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['created'])->toBe(0);
    expect($result['errors'])->not->toBeEmpty();
    expect($result['errors'][0])->toContain('Baris 3');
    expect($result['errors'][0])->toContain('Nama Produk');
    // Baris valid pun tidak disimpan agar file diperbaiki sekaligus.
    expect($store->products()->count())->toBe(0);
});

test('impor menerima harga jual kosong dan mengisi nol', function () {
    [$store, $admin] = makeImportOrg('VAL2');

    $rows = importRows($store, [
        [ProductImportSchema::COL_NAME => 'Tanpa Harga'],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(1);

    $product = $store->products()->where('name', 'Tanpa Harga')->first();
    expect((float) $product->price)->toBe(0.0);
});

test('impor komponen konsumsi memulihkan komponen produk', function () {
    [$store, $admin] = makeImportOrg('KOMP1');

    $product = makeImportProduct($store, [
        'name' => 'Nasi Goreng',
        'sku' => 'MAK-001',
        'price' => 25000,
        'inventory_type' => InventoryType::SELLABLE,
    ]);

    $component = makeImportProduct($store, [
        'name' => 'Beras',
        'sku' => 'BAK-001',
        'price' => 0,
        'inventory_type' => InventoryType::RAW_MATERIAL,
    ]);

    $rows = [
        ['Tipe Komponen', 'ID Produk', 'Nama Produk', 'SKU Produk', 'SKU Varian Produk', 'Nama Varian Produk', 'ID Komponen', 'Nama Komponen', 'SKU Komponen', 'Kuantitas', 'Satuan Dasar', 'Urutan', 'Kode Resep'],
        ['consumption', $product->id, 'Nasi Goreng', 'MAK-001', null, null, $component->id, 'Beras', 'BAK-001', '2', 'pcs', 0, null],
    ];

    $result = app(ProductImportService::class)->importSaleConsumptions($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['imported'])->toBe(1);

    $cons = ProductSaleConsumption::query()
        ->where('product_id', $product->id)
        ->first();

    expect($cons)->not->toBeNull();
    expect($cons->component_product_id)->toBe($component->id);
    expect((float) $cons->quantity)->toBe(2.0);
});

test('impor resep produksi memulihkan resep beserta bahannya', function () {
    [$store, $admin] = makeImportOrg('KOMP2');

    $hasil = makeImportProduct($store, [
        'name' => 'Adonan Roti',
        'sku' => 'HSL-001',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
    ]);

    $tepung = makeImportProduct($store, [
        'name' => 'Tepung',
        'sku' => 'BAK-010',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
    ]);

    $rows = [
        componentSheetHeader(),
        ['recipe', $hasil->id, 'Adonan Roti', 'HSL-001', null, null, $tepung->id, 'Tepung', 'BAK-010', null, null, '500', 'gram', 0, 'RSP-01'],
    ];

    $result = app(ProductImportService::class)->importRecipes($store, $rows, $admin->id);

    expect($result['errors'])->toBe([])
        ->and($result['imported'])->toBe(1);

    $recipe = \App\Models\Recipe::query()->where('store_id', $store->id)->first();

    expect($recipe)->not->toBeNull()
        ->and($recipe->product_id)->toBe($hasil->id)
        ->and($recipe->code)->toBe('RSP-01')
        ->and($recipe->ingredients()->count())->toBe(1)
        ->and((float) $recipe->ingredients()->first()->quantity)->toBe(500.0);
});

test('impor komponen paket memulihkan isi produk paket', function () {
    [$store, $admin] = makeImportOrg('KOMP3');

    $paket = makeImportProduct($store, ['name' => 'Paket Hemat', 'sku' => 'PKT-010']);
    $isi = makeImportProduct($store, ['name' => 'Air Mineral', 'sku' => 'MIN-010']);

    $rows = [
        componentSheetHeader(),
        ['bundle', $paket->id, 'Paket Hemat', 'PKT-010', null, null, $isi->id, 'Air Mineral', 'MIN-010', null, null, '2', 'pcs', 0, null],
    ];

    $result = app(ProductImportService::class)->importBundleItems($store, $rows, $admin->id);

    expect($result['errors'])->toBe([])
        ->and($result['imported'])->toBe(1)
        ->and($paket->fresh()->is_bundle)->toBeTrue();

    $item = \App\Models\ProductBundleItem::query()->where('bundle_product_id', $paket->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->component_product_id)->toBe($isi->id)
        ->and((float) $item->quantity)->toBe(2.0);
});

test('impor varian membuat varian dan menandai produk bervarian', function () {
    [$store, $admin] = makeImportOrg('VRN1');

    $product = makeImportProduct($store, ['name' => 'Kopi', 'sku' => 'KOP-001']);

    $rows = [
        variantSheetHeader(),
        [$product->id, 'Kopi', 'KOP-001', 'Large', 'KOP-001-L', null, '22000', null, '9000', '4', '1', 'tidak', 'ya', 1],
    ];

    $result = app(ProductImportService::class)->importVariants($store, $rows, $admin->id);

    expect($result['errors'])->toBe([])
        ->and($result['imported'])->toBe(1)
        ->and($product->fresh()->has_variants)->toBeTrue();

    $variant = \App\Models\ProductVariant::query()->where('product_id', $product->id)->first();

    expect($variant)->not->toBeNull()
        ->and($variant->name)->toBe('Large')
        ->and($variant->sku)->toBe('KOP-001-L')
        ->and((float) $variant->price)->toBe(22000.0)
        ->and((float) $variant->stock_quantity)->toBe(4.0)
        ->and($variant->store_id)->toBe($store->id);
});

test('impor varian memperbarui varian yang sudah ada tanpa menggandakan', function () {
    [$store, $admin] = makeImportOrg('VRN2');

    $product = makeImportProduct($store, ['name' => 'Kopi', 'sku' => 'KOP-002', 'has_variants' => true]);
    $variant = \App\Models\ProductVariant::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'name' => 'Large',
        'sku' => 'KOP-002-L',
        'price' => 20000,
        'stock_quantity' => 1,
    ]);

    $rows = [
        variantSheetHeader(),
        [$product->id, 'Kopi', 'KOP-002', 'Large', 'KOP-002-L', null, '25000', null, null, '6', '2', 'tidak', 'ya', 0],
    ];

    $result = app(ProductImportService::class)->importVariants($store, $rows, $admin->id);

    expect($result['errors'])->toBe([])
        ->and(\App\Models\ProductVariant::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and((float) $variant->fresh()->price)->toBe(25000.0)
        ->and((float) $variant->fresh()->stock_quantity)->toBe(6.0);
});

test('file ekspor plus komponen dapat diimpor utuh ke toko lain', function () {
    [$sourceStore, $sourceAdmin] = makeImportOrg('RT1', ['business_type' => 'fnb']);
    [$targetStore] = makeImportOrg('RT2', ['business_type' => 'fnb']);

    // Bahan baku, menu dengan komponen & opsi modifier, menu bervarian,
    // hasil produksi dengan resep, dan produk paket.
    $gula = makeImportProduct($sourceStore, [
        'name' => 'Gula',
        'sku' => 'BHN-GULA',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => 1000,
    ]);

    $menu = makeImportProduct($sourceStore, [
        'name' => 'Kopi Susu',
        'sku' => 'MNU-KOPSU',
        'price' => 18000,
        'wholesale_price' => 16000,
        'track_stock' => false,
        'modifier_options' => [['key' => 'extra_shot', 'label' => 'Extra Shot', 'price' => 5000.0]],
    ]);

    $menuVarian = makeImportProduct($sourceStore, [
        'name' => 'Teh',
        'sku' => 'MNU-TEH',
        'price' => 8000,
        'has_variants' => true,
    ]);

    $varianLarge = \App\Models\ProductVariant::query()->create([
        'store_id' => $sourceStore->id,
        'product_id' => $menuVarian->id,
        'name' => 'Large',
        'sku' => 'MNU-TEH-L',
        'price' => 12000,
        'stock_quantity' => 3,
        'sort_order' => 1,
    ]);

    $adonan = makeImportProduct($sourceStore, [
        'name' => 'Adonan',
        'sku' => 'HSL-ADONAN',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
    ]);

    $paket = makeImportProduct($sourceStore, [
        'name' => 'Paket Berdua',
        'sku' => 'PKT-BERDUA',
        'price' => 30000,
        'is_bundle' => true,
    ]);

    ProductSaleConsumption::query()->create([
        'store_id' => $sourceStore->id,
        'product_id' => $menu->id,
        'component_product_id' => $gula->id,
        'quantity' => 15,
        'base_unit' => BaseUnit::GRAM,
    ]);

    ProductSaleConsumption::query()->create([
        'store_id' => $sourceStore->id,
        'product_id' => $menuVarian->id,
        'product_variant_id' => $varianLarge->id,
        'component_product_id' => $gula->id,
        'quantity' => 25,
        'base_unit' => BaseUnit::GRAM,
    ]);

    $recipe = \App\Models\Recipe::query()->create([
        'store_id' => $sourceStore->id,
        'name' => 'Resep Adonan',
        'code' => 'RSP-ADONAN',
        'product_id' => $adonan->id,
        'output_quantity' => 1,
        'output_unit' => BaseUnit::PIECE,
    ]);
    \App\Models\RecipeIngredient::query()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $gula->id,
        'quantity' => 200,
        'base_unit' => BaseUnit::GRAM,
    ]);

    \App\Models\ProductBundleItem::query()->create([
        'bundle_product_id' => $paket->id,
        'component_product_id' => $menu->id,
        'quantity' => 2,
        'sort_order' => 0,
    ]);

    // Ekspor dari toko sumber.
    $path = downloadToFile(
        $this->actingAs($sourceAdmin)->get('/products/export/with-components')->assertOk(),
        'roundtrip',
    );

    $upload = new UploadedFile($path, 'ekspor.xlsx', null, null, true);
    $importer = new \App\Imports\ProductsImport;
    $service = app(ProductImportService::class);

    // Impor ke toko tujuan dengan urutan yang sama seperti ProductController::importStore.
    $productResult = $service->import($targetStore, $importer->rowsFrom($upload));
    $variantResult = $service->importVariants($targetStore, $importer->variantRowsFrom($upload));
    $componentRows = $importer->componentRowsFrom($upload);
    $bundleResult = $service->importBundleItems($targetStore, $componentRows);
    $consumptionResult = $service->importSaleConsumptions($targetStore, $componentRows);
    $recipeResult = $service->importRecipes($targetStore, $componentRows);

    expect($productResult['errors'])->toBe([])
        ->and($variantResult['errors'])->toBe([])
        ->and($bundleResult['errors'])->toBe([])
        ->and($consumptionResult['errors'])->toBe([])
        ->and($recipeResult['errors'])->toBe([])
        ->and($productResult['created'])->toBe(5);

    $targetMenu = $targetStore->products()->where('sku', 'MNU-KOPSU')->firstOrFail();
    $targetGula = $targetStore->products()->where('sku', 'BHN-GULA')->firstOrFail();
    $targetTeh = $targetStore->products()->where('sku', 'MNU-TEH')->firstOrFail();
    $targetPaket = $targetStore->products()->where('sku', 'PKT-BERDUA')->firstOrFail();
    $targetAdonan = $targetStore->products()->where('sku', 'HSL-ADONAN')->firstOrFail();

    // Pengaturan produk ikut tersalin.
    expect((float) $targetMenu->wholesale_price)->toBe(16000.0)
        ->and($targetMenu->track_stock)->toBeFalse()
        ->and($targetMenu->modifier_options)->toHaveCount(1)
        ->and($targetMenu->modifier_options[0]['key'])->toBe('extra_shot')
        ->and($targetMenu->modifier_options[0]['label'])->toBe('Extra Shot')
        ->and((float) $targetMenu->modifier_options[0]['price'])->toBe(5000.0);

    // Komponen menu, termasuk yang menunjuk varian.
    $menuComponent = $targetMenu->saleConsumptions()->firstOrFail();
    expect((float) $menuComponent->quantity)->toBe(15.0)
        ->and($menuComponent->component_product_id)->toBe($targetGula->id);

    $targetVarian = $targetTeh->variants()->where('sku', 'MNU-TEH-L')->firstOrFail();
    $varianComponent = $targetTeh->saleConsumptions()->whereNotNull('product_variant_id')->firstOrFail();
    expect($targetTeh->has_variants)->toBeTrue()
        ->and((float) $targetVarian->price)->toBe(12000.0)
        ->and($varianComponent->product_variant_id)->toBe($targetVarian->id)
        ->and((float) $varianComponent->quantity)->toBe(25.0);

    // Komponen paket.
    $targetBundleItem = \App\Models\ProductBundleItem::query()->where('bundle_product_id', $targetPaket->id)->firstOrFail();
    expect($targetPaket->fresh()->is_bundle)->toBeTrue()
        ->and($targetBundleItem->component_product_id)->toBe($targetMenu->id)
        ->and((float) $targetBundleItem->quantity)->toBe(2.0);

    // Resep produksi.
    $targetRecipe = \App\Models\Recipe::query()->where('store_id', $targetStore->id)->firstOrFail();
    expect($targetRecipe->product_id)->toBe($targetAdonan->id)
        ->and($targetRecipe->ingredients()->count())->toBe(1)
        ->and($targetRecipe->ingredients()->first()->product_id)->toBe($targetGula->id)
        ->and((float) $targetRecipe->ingredients()->first()->quantity)->toBe(200.0);

    @unlink($path);
});

test('ekspor plus komponen memuat sheet produk varian dan komponen', function () {
    [$store, $admin] = makeImportOrg('EXP2');

    $menu = makeImportProduct($store, ['name' => 'Es Kopi', 'sku' => 'MNU-001']);
    $bahan = makeImportProduct($store, [
        'name' => 'Gula',
        'sku' => 'BHN-001',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
    ]);

    \App\Models\ProductVariant::query()->create([
        'store_id' => $store->id,
        'product_id' => $menu->id,
        'name' => 'Large',
        'sku' => 'MNU-001-L',
        'price' => 20000,
    ]);
    $menu->update(['has_variants' => true]);

    ProductSaleConsumption::query()->create([
        'store_id' => $store->id,
        'product_id' => $menu->id,
        'component_product_id' => $bahan->id,
        'quantity' => 15,
        'base_unit' => BaseUnit::GRAM,
    ]);

    $response = $this->actingAs($admin)->get('/products/export/with-components');
    $response->assertOk();

    $path = downloadToFile($response, 'expkomp');
    $sheets = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Imports\ProductsImport, $path);

    expect(count($sheets))->toBe(3);

    $variantHeader = array_map(fn ($c) => (string) $c, $sheets[1][0]);
    $componentHeader = array_map(fn ($c) => (string) $c, $sheets[2][0]);

    expect($variantHeader)->toContain('Nama Varian')
        ->and($componentHeader)->toContain('SKU Varian Komponen')
        ->and(count($sheets[1]))->toBe(2)
        ->and(count($sheets[2]))->toBe(2);

    @unlink($path);
});

test('impor menolak nilai bukan angka dan angka negatif', function () {
    [$store, $admin] = makeImportOrg('VAL3');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Harga Salah',
            ProductImportSchema::COL_PRICE => 'seribu',
        ],
        [
            ProductImportSchema::COL_NAME => 'Stok Negatif',
            ProductImportSchema::COL_PRICE => '1000',
            ProductImportSchema::COL_STOCK => '-5',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    $joined = implode(' | ', $result['errors']);
    expect($joined)->toContain('harus berupa angka');
    expect($joined)->toContain('tidak boleh negatif');
    expect($store->products()->count())->toBe(0);
});

test('impor menolak sku duplikat di dalam file', function () {
    [$store, $admin] = makeImportOrg('VAL4');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk A',
            ProductImportSchema::COL_SKU => 'SKU-1',
            ProductImportSchema::COL_PRICE => '1000',
        ],
        [
            ProductImportSchema::COL_NAME => 'Produk B',
            ProductImportSchema::COL_SKU => 'SKU-1',
            ProductImportSchema::COL_PRICE => '2000',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('dipakai lebih dari satu kali');
    expect($store->products()->count())->toBe(0);
});

test('impor menolak barcode yang sudah dipakai produk lain', function () {
    [$store, $admin] = makeImportOrg('VAL5');
    makeImportProduct($store, ['sku' => 'LAMA-1', 'barcode' => '8991234567890']);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk Baru',
            ProductImportSchema::COL_SKU => 'BARU-1',
            ProductImportSchema::COL_BARCODE => '8991234567890',
            ProductImportSchema::COL_PRICE => '1000',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('sudah dipakai produk lain');
    expect($store->products()->count())->toBe(1);
});

test('impor menolak tipe inventori dan nilai boolean yang tidak dikenal', function () {
    [$store, $admin] = makeImportOrg('VAL6');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Tipe Salah',
            ProductImportSchema::COL_PRICE => '1000',
            ProductImportSchema::COL_INVENTORY_TYPE => 'barang aneh',
        ],
        [
            ProductImportSchema::COL_NAME => 'Boolean Salah',
            ProductImportSchema::COL_PRICE => '1000',
            ProductImportSchema::COL_TRACK_STOCK => 'mungkin',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    $joined = implode(' | ', $result['errors']);
    expect($joined)->toContain('tidak dikenal');
    expect($joined)->toContain('hanya menerima ya atau tidak');
    expect($store->products()->count())->toBe(0);
});

test('impor memperbarui produk bervarian dan produk paket tanpa mengubah stoknya', function () {
    [$store, $admin] = makeImportOrg('VAL7');
    $variantProduct = makeImportProduct($store, ['sku' => 'VAR-1', 'name' => 'Produk Varian', 'has_variants' => true, 'stock_quantity' => 10]);
    $bundleProduct = makeImportProduct($store, ['sku' => 'PKT-1', 'name' => 'Produk Paket', 'is_bundle' => true, 'stock_quantity' => 7]);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk Varian',
            ProductImportSchema::COL_SKU => 'VAR-1',
            ProductImportSchema::COL_PRICE => '9000',
            ProductImportSchema::COL_STOCK => '999',
        ],
        [
            ProductImportSchema::COL_NAME => 'Produk Paket',
            ProductImportSchema::COL_SKU => 'PKT-1',
            ProductImportSchema::COL_PRICE => '9000',
            ProductImportSchema::COL_STOCK => '999',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['updated'])->toBe(2);

    // Harga ikut diperbarui, tetapi stok varian/paket tidak boleh tertimpa dari sheet produk.
    expect((float) $variantProduct->fresh()->price)->toBe(9000.0)
        ->and((float) $variantProduct->fresh()->stock_quantity)->toBe(10.0)
        ->and((float) $bundleProduct->fresh()->price)->toBe(9000.0)
        ->and((float) $bundleProduct->fresh()->stock_quantity)->toBe(7.0);
});

test('impor menolak file tanpa kolom nama produk', function () {
    [$store, $admin] = makeImportOrg('VAL8');

    $result = app(ProductImportService::class)->import($store, [
        ['Kolom Aneh', 'Kolom Lain'],
        ['nilai', 'nilai'],
    ], $admin->id);

    expect($result['errors'])->not->toBeEmpty();
    expect($result['errors'][0])->toContain('Nama Produk');
});

test('impor menolak file tanpa baris data', function () {
    [$store, $admin] = makeImportOrg('VAL9');

    $result = app(ProductImportService::class)->import(
        $store,
        [ProductImportSchema::headingsFor($store)],
        $admin->id,
    );

    expect($result['errors'])->not->toBeEmpty();
    expect($result['errors'][0])->toContain('Tidak ada baris data');
});

test('impor lewat http menampilkan pesan error dan tidak mengubah data', function () {
    [$store, $admin] = makeImportOrg('VAL10');
    makeImportProduct($store);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk Rusak',
            ProductImportSchema::COL_PRICE => 'bukan angka',
        ],
    ]);

    $path = writeImportFile($rows);

    $response = $this->actingAs($admin)
        ->from(route('products.index'))
        ->post('/products/import', [
            'file' => new UploadedFile($path, 'impor.xlsx', null, null, true),
        ]);

    $response->assertRedirect(route('products.index'));
    $response->assertSessionHasErrors('file');
    expect($store->products()->count())->toBe(1);

    @unlink($path);
});

test('impor menolak file dengan format yang tidak didukung', function () {
    [, $admin] = makeImportOrg('VAL11');

    $response = $this->actingAs($admin)
        ->from(route('products.index'))
        ->post('/products/import', [
            'file' => UploadedFile::fake()->create('produk.pdf', 10, 'application/pdf'),
        ]);

    $response->assertSessionHasErrors('file');
});

test('kasir tidak dapat mengimpor produk', function () {
    [$store, , $cashier] = makeImportOrg('VAL12');

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Produk Kasir',
            ProductImportSchema::COL_PRICE => '1000',
        ],
    ]);

    $path = writeImportFile($rows);

    $this->actingAs($cashier)
        ->post('/products/import', [
            'file' => new UploadedFile($path, 'impor.xlsx', null, null, true),
        ])
        ->assertRedirect(route('dashboard'));

    expect($store->products()->count())->toBe(0);

    @unlink($path);
});

// ═══════════════════════════════════════════════════════
// Toko apotek & mode batch
// ═══════════════════════════════════════════════════════

test('template apotek memuat kolom batch dan kelas penjualan obat', function () {
    [$store] = makeImportOrg('APT1', ['business_type' => 'pharmacy']);

    $keys = ProductImportSchema::keysFor($store);

    expect($keys)->toContain(ProductImportSchema::COL_BATCH_CODE);
    expect($keys)->toContain(ProductImportSchema::COL_EXPIRY_DATE);
    expect($keys)->toContain(ProductImportSchema::COL_PHARMACY_CLASS);
    expect($keys)->toContain(ProductImportSchema::COL_PHARMACY_REG_NO);
});

test('impor apotek mewajibkan kelas penjualan obat', function () {
    [$store, $admin] = makeImportOrg('APT2', ['business_type' => 'pharmacy']);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Paracetamol 500mg',
            ProductImportSchema::COL_PRICE => '5000',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('Kelas Penjualan Obat');
    expect($store->products()->count())->toBe(0);
});

test('impor apotek mewajibkan tanggal kedaluwarsa bila ada stok awal', function () {
    [$store, $admin] = makeImportOrg('APT3', ['business_type' => 'pharmacy']);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Amoxicillin 500mg',
            ProductImportSchema::COL_PRICE => '12000',
            ProductImportSchema::COL_STOCK => '20',
            ProductImportSchema::COL_PHARMACY_CLASS => 'prescription',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('Tanggal Kedaluwarsa');
    expect($store->products()->count())->toBe(0);
});

test('impor apotek membuat batch stok awal beserta tanggal kedaluwarsa', function () {
    [$store, $admin] = makeImportOrg('APT4', ['business_type' => 'pharmacy']);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Vitamin C 500mg',
            ProductImportSchema::COL_SKU => 'OBT-001',
            ProductImportSchema::COL_PRICE => '15000',
            ProductImportSchema::COL_STOCK => '30',
            ProductImportSchema::COL_BATCH_CODE => 'B-2601',
            ProductImportSchema::COL_EXPIRY_DATE => '2027-12-31',
            ProductImportSchema::COL_PHARMACY_CLASS => 'otc',
            ProductImportSchema::COL_PHARMACY_REG_NO => 'DBL1234567890A1',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect($result['errors'])->toBe([]);
    expect($result['created'])->toBe(1);

    $product = $store->products()->where('sku', 'OBT-001')->first();
    expect($product->pharmacy_sale_class)->toBe('otc');
    expect($product->pharmacy_registration_no)->toBe('DBL1234567890A1');

    $batch = \App\Models\ProductBatch::where('product_id', $product->id)->first();
    expect($batch)->not->toBeNull();
    expect((float) $batch->quantity_remaining)->toBe(30.0);
    expect($batch->batch_code)->toBe('B-2601');
    expect((string) $batch->expiry_date)->toContain('2027-12-31');
});

test('impor mode batch menolak perubahan stok produk lama', function () {
    [$store, $admin] = makeImportOrg('BATCH1', ['settings' => ['batch_tracking_enabled' => true]]);
    makeImportProduct($store, ['sku' => 'BTC-1', 'stock_quantity' => 10]);

    $rows = importRows($store, [
        [
            ProductImportSchema::COL_NAME => 'Es Teh Manis',
            ProductImportSchema::COL_SKU => 'BTC-1',
            ProductImportSchema::COL_PRICE => '6000',
            ProductImportSchema::COL_STOCK => '50',
        ],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('stok produk lama tidak dapat diubah');
});

// ═══════════════════════════════════════════════════════
// Batas paket langganan
// ═══════════════════════════════════════════════════════

test('impor menolak jika jumlah produk baru melebihi batas paket', function () {
    $plan = \App\Models\SubscriptionPlan::query()->create([
        'name' => 'Starter Uji',
        'slug' => 'starter',
        'price' => 0,
        'max_products' => 2,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    [$store, $admin] = makeImportOrg('LIM1', [
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    makeImportProduct($store, ['sku' => 'ADA-1']);

    $rows = importRows($store, [
        [ProductImportSchema::COL_NAME => 'Produk 1', ProductImportSchema::COL_SKU => 'B-1', ProductImportSchema::COL_PRICE => '1000'],
        [ProductImportSchema::COL_NAME => 'Produk 2', ProductImportSchema::COL_SKU => 'B-2', ProductImportSchema::COL_PRICE => '1000'],
    ]);

    $result = app(ProductImportService::class)->import($store, $rows, $admin->id);

    expect(implode(' ', $result['errors']))->toContain('membatasi hingga 2 produk');
    expect($store->products()->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════
// Normalisasi nilai
// ═══════════════════════════════════════════════════════

test('header impor toleran terhadap variasi penulisan', function () {
    expect(ProductImportSchema::normalizeHeader('Nama Produk*'))->toBe(ProductImportSchema::COL_NAME);
    expect(ProductImportSchema::normalizeHeader('  NAMA PRODUK  '))->toBe(ProductImportSchema::COL_NAME);
    expect(ProductImportSchema::normalizeHeader('Nama Barang'))->toBe(ProductImportSchema::COL_NAME);
    expect(ProductImportSchema::normalizeHeader('Harga Jual*'))->toBe(ProductImportSchema::COL_PRICE);
    expect(ProductImportSchema::normalizeHeader('Pajak (%)'))->toBe(ProductImportSchema::COL_TAX);
    expect(ProductImportSchema::normalizeHeader(''))->toBe('');
});

test('angka gaya indonesia dan internasional terbaca benar', function () {
    expect(ProductImportSchema::parseNumber('10.000'))->toBe(10000.0);
    expect(ProductImportSchema::parseNumber('1.234.567'))->toBe(1234567.0);
    expect(ProductImportSchema::parseNumber('1.234,56'))->toBe(1234.56);
    expect(ProductImportSchema::parseNumber('0,5'))->toBe(0.5);
    expect(ProductImportSchema::parseNumber('10.5'))->toBe(10.5);
    expect(ProductImportSchema::parseNumber('Rp 25.000'))->toBe(25000.0);
    expect(ProductImportSchema::parseNumber(15000))->toBe(15000.0);
    expect(ProductImportSchema::parseNumber('abc'))->toBeNull();
    expect(ProductImportSchema::parseNumber(''))->toBeNull();
});

test('nilai boolean menerima beragam penulisan', function () {
    expect(ProductImportSchema::parseBoolean('ya', false))->toBeTrue();
    expect(ProductImportSchema::parseBoolean('YA', false))->toBeTrue();
    expect(ProductImportSchema::parseBoolean('true', false))->toBeTrue();
    expect(ProductImportSchema::parseBoolean('1', false))->toBeTrue();
    expect(ProductImportSchema::parseBoolean('tidak', true))->toBeFalse();
    expect(ProductImportSchema::parseBoolean('0', true))->toBeFalse();
    expect(ProductImportSchema::parseBoolean('', true))->toBeTrue();
    expect(ProductImportSchema::parseBoolean(null, false))->toBeFalse();
    expect(ProductImportSchema::parseBoolean('mungkin', false))->toBeNull();
});
