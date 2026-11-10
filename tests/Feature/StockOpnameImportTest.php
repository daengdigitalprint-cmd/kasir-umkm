<?php

use App\Imports\StockOpnameSheetImport;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockOpnameImport;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AdjustmentStockOperations;
use App\Support\InventoryCosting;
use App\Support\ProductImportSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Test Tahap 4 — unggah berkas hasil hitung stock opname.
 */
function makeImportOpnameStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Impor Opname',
        'slug' => 'opname-impor-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeImportOpnameProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Impor Opname',
        'price' => 15000,
        'cost_price' => 9000,
        'average_cost' => 9000,
        'stock_quantity' => 60,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ], $attrs));
}

function openImportOpnameSession(User $user, Store $store, array $products): StockAdjustment
{
    $document = StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_OPNAME,
        'reason_code' => StockAdjustment::REASON_OPNAME,
        'status' => StockAdjustment::STATUS_DRAFT,
        'counted_at' => now(),
        'created_by' => $user->id,
    ]);

    foreach ($products as $product) {
        $systemQty = AdjustmentStockOperations::currentQuantity($product);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $document->id,
            'product_id' => $product->id,
            'system_quantity' => $systemQty,
            'counted_quantity' => $systemQty,
            'variance_quantity' => '0.0000',
            'unit_cost' => InventoryCosting::currentAverageCost($product),
            'variance_value' => '0.00',
        ]);
    }

    return $document->fresh();
}

/**
 * Susun berkas CSV lembar hitung untuk diunggah.
 *
 * @param  list<list<mixed>>  $rows
 */
function importOpnameCsv(array $rows, ?array $header = null): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $header ?? ['SKU', 'Barcode', 'Nama Produk', 'Varian', 'Satuan', 'Stok Sistem', 'Stok Fisik', 'Catatan'], ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '\\');
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
}

function uploadOpnameCsv(User $user, StockAdjustment $document, string $csv, array $extra = [])
{
    return test()->actingAs($user)->post(route('stock-opnames.imports.store', $document), array_merge([
        'file' => UploadedFile::fake()->createWithContent('hitung.csv', $csv),
    ], $extra));
}

function latestOpnameImport(): StockOpnameImport
{
    return StockOpnameImport::query()->latest('id')->firstOrFail();
}

function previewBySku(array $preview, string $sku): ?array
{
    foreach ($preview as $row) {
        if (($row['sku'] ?? '') === $sku) {
            return $row;
        }
    }

    return null;
}

it('mengunduh lembar hitung berisi daftar produk beserta snapshot stok sistem', function () {
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A', 'name' => 'Produk A', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B', 'name' => 'Produk B', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $response = $this->actingAs($user)->get(route('stock-opnames.template', $document));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
    expect($response->headers->get('content-disposition'))->toContain('lembar-hitung');

    $rows = (new StockOpnameSheetImport)->rowsFrom($response->getFile()->getPathname());
    $header = array_map([ProductImportSchema::class, 'normalizeHeader'], $rows[0]);
    $colSku = array_search(ProductImportSchema::COL_SKU, $header, true);
    $colSystem = array_search('stok_sistem', $header, true);
    $colCounted = array_search(StockOpnameSheetImport::COL_COUNTED, $header, true);

    expect($colSku)->not->toBeFalse();
    expect($colSystem)->not->toBeFalse();
    expect($colCounted)->not->toBeFalse();

    $body = collect($rows)->skip(1)->keyBy(fn (array $row): string => (string) ($row[$colSku] ?? ''));

    expect((float) $body['SKU-A'][$colSystem])->toEqual(60.0);
    expect((float) $body['SKU-B'][$colSystem])->toEqual(40.0);
    expect(trim((string) $body['SKU-A'][$colCounted]))->toBe('');
});

it('memproses unggahan valid dan menampilkan preview selisih tanpa mengubah stok', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([
        ['SKU-A', '', 'Produk A', '', 'pcs', '60', '55', 'satu dus kurang'],
        ['SKU-B', '', 'Produk B', '', 'pcs', '40', '42', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();

    $import = latestOpnameImport();
    expect($import->status)->toBe(StockOpnameImport::STATUS_COMPLETED);

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('can_apply', true)
        ->assertJsonPath('summary.matched_rows', 2)
        ->assertJsonPath('summary.error_rows', 0);

    $preview = $show->json('preview');
    expect($preview)->toHaveCount(2);

    $rowA = previewBySku($preview, 'SKU-A');
    expect((float) $rowA['system_quantity'])->toEqual(60.0);
    expect((float) $rowA['counted_quantity'])->toEqual(55.0);
    expect((float) $rowA['variance_quantity'])->toEqual(-5.0);
    expect((float) $rowA['variance_value'])->toEqual(-45000.0);
    expect($rowA['note'])->toBe('satu dus kurang');
    expect($rowA['missing'])->toBeFalse();

    $rowB = previewBySku($preview, 'SKU-B');
    expect((float) $rowB['variance_quantity'])->toEqual(2.0);
    expect((float) $rowB['variance_value'])->toEqual(18000.0);

    // Preview tidak boleh menulis apa pun ke lembar hitung.
    $lineA = $document->lines()->where('product_id', $productA->id)->firstOrFail();
    expect((float) $lineA->counted_quantity)->toEqual(60.0);
    expect((float) $lineA->variance_quantity)->toEqual(0.0);
});

it('melaporkan kesalahan per baris untuk SKU tak ditemukan, stok kosong, negatif, duplikat, dan bukan angka', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B']);
    $productC = makeImportOpnameProduct($store, ['sku' => 'SKU-C']);
    $document = openImportOpnameSession($user, $store, [$productA, $productB, $productC]);

    $csv = importOpnameCsv([
        ['SKU-X', '', '', '', '', '5', '5', ''],
        ['SKU-A', '', '', '', '', '60', '', ''],
        ['SKU-B', '', '', '', '', '40', '-3', ''],
        ['SKU-B', '', '', '', '', '40', '5', ''],
        ['SKU-C', '', '', '', '', '30', 'abc', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('can_apply', false)
        ->assertJsonPath('summary.error_rows', 5)
        ->assertJsonPath('summary.matched_rows', 0);

    $errors = $show->json('errors');
    expect(implode("\n", $errors))
        ->toContain('Baris 2:')->toContain('SKU «SKU-X» tidak ditemukan')
        ->toContain('Baris 3:')->toContain('kolom Stok Fisik wajib diisi')
        ->toContain('Baris 4:')->toContain('kolom Stok Fisik tidak boleh negatif')
        ->toContain('Baris 5:')->toContain('SKU «SKU-B» sudah diisi pada baris lain')
        ->toContain('Baris 6:')->toContain('kolom Stok Fisik harus berupa angka');
});

it('menandai berkas gagal saat kolom Stok Fisik tidak ditemukan', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $product = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $document = openImportOpnameSession($user, $store, [$product]);

    $csv = importOpnameCsv(
        [['SKU-A', '', '', '', '', '60', '55', '']],
        ['SKU', 'Barcode', 'Nama Produk', 'Varian', 'Satuan', 'Stok Sistem', 'Jumlah', 'Catatan'],
    );

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()->assertJsonPath('status', 'failed');
    expect($show->json('error_message'))->toContain('Stok Fisik');
});

it('menerapkan hasil preview ke lembar hitung setelah konfirmasi', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([
        ['SKU-A', '', '', '', '', '60', '55', 'catatan gudang'],
        ['SKU-B', '', '', '', '', '40', '42', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();

    $import->refresh();
    expect($import->isApplied())->toBeTrue();

    $lineA = $document->lines()->where('product_id', $productA->id)->firstOrFail();
    expect((float) $lineA->counted_quantity)->toEqual(55.0);
    expect((float) $lineA->variance_quantity)->toEqual(-5.0);
    expect((float) $lineA->variance_value)->toEqual(-45000.0);
    expect($lineA->line_notes)->toBe('catatan gudang');

    $lineB = $document->lines()->where('product_id', $productB->id)->firstOrFail();
    expect((float) $lineB->counted_quantity)->toEqual(42.0);
    expect((float) $lineB->variance_quantity)->toEqual(2.0);
    expect((float) $lineB->variance_value)->toEqual(18000.0);

    expect((float) $document->fresh()->total_variance_value)->toEqual(-27000.0);
});

it('menolak menerapkan berkas yang masih memiliki kesalahan', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B']);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([
        ['SKU-A', '', '', '', '', '60', '55', ''],
        ['SKU-HILANG', '', '', '', '', '1', '1', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))
        ->assertSessionHasErrors('import');

    expect($import->refresh()->isApplied())->toBeFalse();
    expect((float) $document->lines()->where('product_id', $productA->id)->firstOrFail()->counted_quantity)
        ->toEqual(60.0);
});

it('menolak menerapkan berkas yang sudah pernah diterapkan', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $product = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $document = openImportOpnameSession($user, $store, [$product]);

    $csv = importOpnameCsv([['SKU-A', '', '', '', '', '60', '55', '']]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();
    $this->post(route('stock-opnames.imports.apply', [$document, $import]))
        ->assertSessionHasErrors('import');
});

it('menolak menerapkan hasil saat sesi sudah final', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $product = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $document = openImportOpnameSession($user, $store, [$product]);

    $csv = importOpnameCsv([['SKU-A', '', '', '', '', '60', '55', '']]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $document->update(['status' => StockAdjustment::STATUS_POSTED]);

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))
        ->assertSessionHasErrors('import');

    expect($import->refresh()->isApplied())->toBeFalse();
});

it('mengabaikan produk yang tidak ada di berkas secara bawaan', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([['SKU-A', '', '', '', '', '60', '60', '']]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('summary.counted_lines', 1)
        ->assertJsonPath('summary.missing_lines', 0);

    expect($show->json('preview'))->toHaveCount(1);
    expect((float) $document->lines()->where('product_id', $productB->id)->firstOrFail()->counted_quantity)
        ->toEqual(40.0);
});

it('menganggap stok 0 untuk produk yang tidak ada di berkas pada mode zero', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([['SKU-A', '', '', '', '', '60', '60', '']]);

    uploadOpnameCsv($user, $document, $csv, ['missing_mode' => StockOpnameImport::MODE_ZERO])->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('summary.counted_lines', 2)
        ->assertJsonPath('summary.missing_lines', 1);

    $missing = previewBySku($show->json('preview'), 'SKU-B');
    expect($missing['missing'])->toBeTrue();
    expect((float) $missing['counted_quantity'])->toEqual(0.0);
    expect((float) $missing['variance_quantity'])->toEqual(-40.0);
    expect((float) $missing['variance_value'])->toEqual(-360000.0);

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();

    $lineB = $document->lines()->where('product_id', $productB->id)->firstOrFail();
    expect((float) $lineB->counted_quantity)->toEqual(0.0);
    expect((float) $lineB->variance_quantity)->toEqual(-40.0);
});

it('menolak akses impor milik dokumen lain', function () {
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['sku' => 'SKU-A']);
    $productB = makeImportOpnameProduct($store, ['sku' => 'SKU-B']);
    $documentA = openImportOpnameSession($user, $store, [$productA]);
    $documentB = openImportOpnameSession($user, $store, [$productB]);

    $import = StockOpnameImport::create([
        'store_id' => $store->id,
        'stock_adjustment_id' => $documentA->id,
        'created_by' => $user->id,
        'file_path' => 'stock-opname-imports/contoh.csv',
        'file_name' => 'contoh.csv',
        'missing_mode' => StockOpnameImport::MODE_IGNORE,
        'status' => StockOpnameImport::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->getJson(route('stock-opnames.imports.show', [$documentB, $import]))
        ->assertNotFound();
});

it('mencocokkan baris lewat Nama Produk saat SKU dan Barcode kosong', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['name' => 'Kurma Ajwa', 'stock_quantity' => 60]);
    $productB = makeImportOpnameProduct($store, ['name' => 'Kurma Sukari', 'stock_quantity' => 40]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([
        ['', '', 'Kurma Ajwa', '', 'pcs', '60', '55', 'satu dus kurang'],
        ['', '', 'Kurma Sukari', '', 'pcs', '40', '42', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('can_apply', true)
        ->assertJsonPath('summary.matched_rows', 2)
        ->assertJsonPath('summary.error_rows', 0);

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();

    $lineA = $document->lines()->where('product_id', $productA->id)->firstOrFail();
    expect((float) $lineA->counted_quantity)->toEqual(55.0);
    expect((float) $lineA->variance_quantity)->toEqual(-5.0);
    expect($lineA->line_notes)->toBe('satu dus kurang');

    $lineB = $document->lines()->where('product_id', $productB->id)->firstOrFail();
    expect((float) $lineB->counted_quantity)->toEqual(42.0);
});

it('memasangkan baris produk kembar identik mengikuti urutan baris berkas', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productA = makeImportOpnameProduct($store, ['name' => 'Dompet Mekkah', 'stock_quantity' => 10]);
    $productB = makeImportOpnameProduct($store, ['name' => 'Dompet Mekkah', 'stock_quantity' => 20]);
    $document = openImportOpnameSession($user, $store, [$productA, $productB]);

    $csv = importOpnameCsv([
        ['', '', 'Dompet Mekkah', '', 'pcs', '10', '7', ''],
        ['', '', 'Dompet Mekkah', '', 'pcs', '20', '9', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('summary.matched_rows', 2)
        ->assertJsonPath('summary.error_rows', 0);

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();

    expect((float) $document->lines()->where('product_id', $productA->id)->firstOrFail()->counted_quantity)
        ->toEqual(7.0);
    expect((float) $document->lines()->where('product_id', $productB->id)->firstOrFail()->counted_quantity)
        ->toEqual(9.0);
});

it('mempersempit kandidat nama kembar lewat kolom Satuan', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $productPcs = makeImportOpnameProduct($store, ['name' => 'Kurma Sukari', 'unit' => 'pcs', 'stock_quantity' => 10]);
    $productBox = makeImportOpnameProduct($store, ['name' => 'Kurma Sukari', 'unit' => 'box', 'stock_quantity' => 20]);
    $document = openImportOpnameSession($user, $store, [$productPcs, $productBox]);

    $csv = importOpnameCsv([
        ['', '', 'Kurma Sukari', '', 'box', '20', '5', ''],
        ['', '', 'Kurma Sukari', '', 'pcs', '10', '8', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()->assertJsonPath('summary.error_rows', 0);

    $this->post(route('stock-opnames.imports.apply', [$document, $import]))->assertRedirect();

    expect((float) $document->lines()->where('product_id', $productPcs->id)->firstOrFail()->counted_quantity)
        ->toEqual(8.0);
    expect((float) $document->lines()->where('product_id', $productBox->id)->firstOrFail()->counted_quantity)
        ->toEqual(5.0);
});

it('melaporkan kesalahan saat Nama Produk tidak ditemukan atau kolom identitas kosong', function () {
    Storage::fake('local');
    [$store, $user] = makeImportOpnameStore();
    $product = makeImportOpnameProduct($store, ['name' => 'Kurma Ajwa', 'stock_quantity' => 60]);
    $document = openImportOpnameSession($user, $store, [$product]);

    $csv = importOpnameCsv([
        ['', '', 'Kurma Khalas', '', 'pcs', '10', '5', ''],
        ['', '', '', '', 'pcs', '1', '1', ''],
    ]);

    uploadOpnameCsv($user, $document, $csv)->assertRedirect();
    $import = latestOpnameImport();

    $show = $this->getJson(route('stock-opnames.imports.show', [$document, $import]));
    $show->assertOk()
        ->assertJsonPath('can_apply', false)
        ->assertJsonPath('summary.error_rows', 2);

    $errors = implode("\n", $show->json('errors'));
    expect($errors)
        ->toContain('Baris 2:')->toContain('Nama Produk «Kurma Khalas» tidak ditemukan')
        ->toContain('Baris 3:')->toContain('kolom SKU atau Barcode wajib diisi');
});
