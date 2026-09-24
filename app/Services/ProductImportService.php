<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductSaleConsumption;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Support\BaseUnit;
use App\Support\BatchTracking;
use App\Support\InventoryType;
use App\Support\PharmacyFeatures;
use App\Support\ProductImportSchema;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validasi dan penyimpanan data impor produk.
 *
 * Seluruh baris divalidasi lebih dulu. Bila ada satu saja baris yang tidak valid,
 * tidak ada data yang ditulis sehingga produk yang sudah ada tetap utuh.
 */
class ProductImportService
{
    /** Batas baris data per file supaya proses tetap ringan. */
    public const MAX_ROWS = 2000;

    /** Batas pesan error yang dikembalikan agar notifikasi tetap terbaca. */
    public const MAX_REPORTED_ERRORS = 30;

    /**
     * Cache id kategori (case-insensitive by store) agar impor tidak query DB
     * berulang kali untuk kategori yang sama.
     *
     * @var array<int, array<string, int>>
     */
    private array $categoryIdCache = [];

    /**
     * @param  list<list<mixed>>  $rows  Baris mentah termasuk header pada indeks 0.
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(Store $store, array $rows, ?int $userId = null): array
    {
        if ($rows === []) {
            return $this->failure('File tidak memiliki sheet dengan kolom "Nama Produk". Unduh template impor lalu isi sesuai formatnya.');
        }

        $headerMap = $this->buildHeaderMap(array_shift($rows));

        if (! isset($headerMap[ProductImportSchema::COL_NAME])) {
            return $this->failure('Kolom "Nama Produk" tidak ditemukan pada baris header. Gunakan template impor terbaru.');
        }

        $dataRows = [];
        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            // +2 karena baris 1 adalah header dan indeks array dimulai dari 0.
            $dataRows[$index + 2] = $row;
        }

        if ($dataRows === []) {
            return $this->failure('Tidak ada baris data yang bisa diproses. Isi data mulai baris ke-2 pada sheet template.');
        }

        if (count($dataRows) > self::MAX_ROWS) {
            return $this->failure('Jumlah baris data ('.count($dataRows).') melebihi batas '.self::MAX_ROWS.' baris per file. Pecah file impor menjadi beberapa bagian.');
        }

        // Sortir: bahan baku (raw_material, packaging, production_output) diimpor duluan
        // supaya produk jadi bisa merujuk bahan baku yang sudah ada di database.
        $dataRows = $this->sortRowsByInventoryType($dataRows, $headerMap);

        $context = ['sku' => [], 'barcode' => [], 'name' => [], 'product' => []];
        $errors = [];
        $prepared = [];

        // Pre-load semua produk toko untuk lookup in-memory (hindari N+1 per baris)
        $storeProductIndex = $this->buildStoreProductIndex($store);

        foreach ($dataRows as $line => $row) {
            $rowErrors = [];
            $item = $this->prepareRow($store, $context, $storeProductIndex, $headerMap, $row, $rowErrors);

            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $errors[] = "Baris {$line}: {$message}";
                }

                continue;
            }

            if ($item !== null) {
                $prepared[] = $item;
            }
        }

        $newCount = count(array_filter($prepared, static fn (array $item): bool => $item['product'] === null));
        $maxProducts = StorePlanLimits::maxProducts($store);
        if ($maxProducts !== null && $newCount > 0) {
            $afterImport = StorePlanLimits::currentProductCount($store) + $newCount;
            if ($afterImport > $maxProducts) {
                $errors[] = "Paket Anda membatasi hingga {$maxProducts} produk, sedangkan file ini menambah {$newCount} produk baru (total menjadi {$afterImport}). Naikkan paket di halaman langganan atau kurangi baris produk baru.";
            }
        }

        if ($errors !== []) {
            return [
                'created' => 0,
                'updated' => 0,
                'errors' => $this->capErrors($errors),
            ];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($store, $prepared, $userId, &$created, &$updated) {
            foreach ($prepared as $item) {
                if ($item['product'] === null) {
                    $this->createProduct($store, $item, $userId);
                    $created++;

                    continue;
                }

                $this->updateProduct($store, $item['product'], $item, $userId);
                $updated++;
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => []];
    }

    /**
     * Impor komponen konsumsi penjualan dari sheet "Komponen Produk".
     *
     * Hanya baris bertipe "consumption" yang diproses. Komponen yang sudah ada pada produk
     * yang disebut di file diganti dengan isi file; produk lain tidak disentuh.
     *
     * @param  list<list<mixed>>  $rows  Baris mentah sheet komponen termasuk header pada indeks 0.
     * @return array{imported: int, errors: list<string>}
     */
    public function importSaleConsumptions(Store $store, array $rows, ?int $userId = null): array
    {
        if ($rows === []) {
            return ['imported' => 0, 'errors' => []];
        }

        $headerMap = $this->buildHeaderMap(array_shift($rows));

        if (! isset($headerMap['tipe_komponen'])) {
            return ['imported' => 0, 'errors' => []];
        }

        $products = $store->products()
            ->get(['id', 'name', 'sku', 'inventory_type', 'base_unit', 'has_variants', 'is_bundle', 'is_active', 'track_stock'])
            ->keyBy('id');

        $bySku = [];
        $byName = [];
        foreach ($products as $product) {
            if ($product->sku !== null && $product->sku !== '') {
                $bySku[mb_strtolower($product->sku)] = $product->id;
            }
            $byName[mb_strtolower($product->name)] = $product->id;
        }

        $errors = [];
        $grouped = [];

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $line = $index + 2;

            $type = $this->stringValue($row, $headerMap, 'tipe_komponen');
            if ($type === null || mb_strtolower($type) !== 'consumption') {
                continue;
            }

            $product = $this->resolveSheetProduct(
                $products,
                $bySku,
                $byName,
                $this->stringValue($row, $headerMap, 'sku_produk'),
                $this->stringValue($row, $headerMap, 'nama_produk'),
                $this->rawValue($row, $headerMap, 'id_produk'),
            );

            if ($product === null) {
                $errors[] = "Baris {$line}: produk pemilik komponen tidak ditemukan di toko ini.";

                continue;
            }

            if (InventoryType::of($product) !== InventoryType::SELLABLE || $product->is_bundle) {
                $errors[] = "Baris {$line}: komponen konsumsi hanya berlaku untuk produk jual non-paket («{$product->name}»).";

                continue;
            }

            $component = $this->resolveSheetProduct(
                $products,
                $bySku,
                $byName,
                $this->stringValue($row, $headerMap, 'sku_komponen'),
                $this->stringValue($row, $headerMap, 'nama_komponen'),
                $this->rawValue($row, $headerMap, 'id_komponen'),
            );

            if ($component === null || (int) $component->id === (int) $product->id) {
                $errors[] = "Baris {$line}: komponen tidak valid untuk toko ini.";

                continue;
            }

            if (! $component->is_active || ! $component->track_stock
                || ! in_array(InventoryType::of($component), [InventoryType::RAW_MATERIAL, InventoryType::PRODUCTION_OUTPUT, InventoryType::PACKAGING], true)) {
                $errors[] = "Baris {$line}: komponen «{$component->name}» harus produk aktif, dilacak stoknya, dan bertipe bahan/kemasan/hasil produksi.";

                continue;
            }

            $variantSku = $this->stringValue($row, $headerMap, 'sku_varian_produk');
            $variantName = $this->stringValue($row, $headerMap, 'nama_varian_produk');
            $variantId = null;

            if ($variantSku !== null || $variantName !== null) {
                if (! $product->has_variants) {
                    $errors[] = "Baris {$line}: produk «{$product->name}» tanpa varian tidak boleh memiliki target varian.";

                    continue;
                }

                $variantId = $this->resolveSheetVariant($store, $product, $variantSku, $variantName);
                if ($variantId === null) {
                    $errors[] = "Baris {$line}: varian produk tidak ditemukan untuk «{$product->name}».";

                    continue;
                }
            }

            $rowErrors = [];
            $quantity = $this->numberValue($row, $headerMap, 'kuantitas', 'Kuantitas', $rowErrors);
            if ($rowErrors !== []) {
                $errors[] = "Baris {$line}: ".implode(' ', $rowErrors);

                continue;
            }
            if ($quantity === null || $quantity <= 0) {
                $errors[] = "Baris {$line}: kuantitas konsumsi harus lebih dari nol.";

                continue;
            }

            $inputUnit = $this->stringValue($row, $headerMap, 'satuan_dasar') ?? $component->baseUnit();
            if (! BaseUnit::isConvertible($inputUnit, $component->baseUnit())) {
                $errors[] = "Baris {$line}: satuan «{$inputUnit}» tidak sesuai dengan komponen «{$component->name}».";

                continue;
            }

            $key = $product->id.':'.($variantId ?? 'product').':'.$component->id;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'component_product_id' => $component->id,
                    'quantity' => BaseUnit::toBaseQuantity($quantity, $inputUnit, $component->baseUnit()),
                    'base_unit' => $component->baseUnit(),
                ];
            }
        }

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $this->capErrors($errors)];
        }

        // Susun ulang per produk agar sort_order berurutan dari urutan baris di file.
        $perProduct = [];
        $order = 0;
        foreach ($grouped as $item) {
            $item['sort_order'] = $order++;
            $perProduct[$item['product_id']][] = $item;
        }

        $imported = 0;

        DB::transaction(function () use ($store, $perProduct, &$imported) {
            foreach ($perProduct as $productId => $items) {
                $product = Product::query()->where('store_id', $store->id)->whereKey($productId)->first();
                if (! $product) {
                    continue;
                }

                $product->saleConsumptions()->delete();

                foreach ($items as $item) {
                    ProductSaleConsumption::create([
                        'store_id' => $store->id,
                        'product_id' => $productId,
                        'product_variant_id' => $item['product_variant_id'],
                        'component_product_id' => $item['component_product_id'],
                        'quantity' => $item['quantity'],
                        'base_unit' => $item['base_unit'],
                        'sort_order' => $item['sort_order'],
                    ]);
                    $imported++;
                }
            }
        });

        return ['imported' => $imported, 'errors' => []];
    }

    /**
     * Resolusi produk dari sheet komponen: SKU, lalu nama, lalu ID (hanya bila masih milik toko).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array<string, int>  $bySku
     * @param  array<string, int>  $byName
     */
    private function resolveSheetProduct($products, array $bySku, array $byName, ?string $sku, ?string $name, mixed $id): ?Product
    {
        if ($sku !== null && $sku !== '' && isset($bySku[mb_strtolower($sku)])) {
            return $products->get($bySku[mb_strtolower($sku)]);
        }

        if ($name !== null && $name !== '' && isset($byName[mb_strtolower($name)])) {
            return $products->get($byName[mb_strtolower($name)]);
        }

        $id = is_numeric($id) ? (int) $id : 0;

        return $id > 0 ? $products->get($id) : null;
    }

    /**
     * Resolusi varian target produk. Kembali null bila tidak ditemukan.
     */
    private function resolveSheetVariant(Store $store, Product $product, ?string $sku, ?string $name): ?int
    {
        $query = ProductVariant::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->where('is_active', true);

        if ($sku !== null && $sku !== '') {
            $query->where('sku', $sku);
        } elseif ($name !== null && $name !== '') {
            $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        }

        $variant = $query->first();

        return $variant?->id;
    }

    /**
     * Build in-memory index semua produk toko untuk lookup cepat tanpa query.
     *
     * Saat ada duplikat (case-insensitive) pada satu kunci, produk dengan id terkecil
     * yang dipakai — konsisten dengan orderBy('id')->first() pada query lama.
     *
     * @return array{bySku: array<string, Product>, byBarcode: array<string, Product>, byName: array<string, Product>}
     */
    private function buildStoreProductIndex(Store $store): array
    {
        $products = $store->products()->orderBy('id')->get();
        $bySku = [];
        $byBarcode = [];
        $byName = [];

        foreach ($products as $product) {
            $this->indexProduct($bySku, $product, $product->sku);
            $this->indexProduct($byBarcode, $product, $product->barcode);
            $this->indexProduct($byName, $product, $product->name);
        }

        return ['bySku' => $bySku, 'byBarcode' => $byBarcode, 'byName' => $byName];
    }

    /**
     * Simpan produk ke index hanya bila belum ada atau id-nya lebih kecil
     * (id terkecil menang, konsisten dengan orderBy('id')->first()).
     *
     * @param  array<string, Product>  $index
     */
    private function indexProduct(array &$index, Product $product, mixed $value): void
    {
        if ($value === null || (is_string($value) && $value === '')) {
            return;
        }

        $key = mb_strtolower((string) $value);
        if (! isset($index[$key]) || (int) $product->id < (int) $index[$key]->id) {
            $index[$key] = $product;
        }
    }

    /**
     * @param  array<string, array<string, true>>  $context
     * @param  array{bySku: array<string, Product>, byBarcode: array<string, Product>, byName: array<string, Product>}  $storeProductIndex
     * @param  array<string, int>  $headerMap
     * @param  list<mixed>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function prepareRow(Store $store, array &$context, array $storeProductIndex, array $headerMap, array $row, array &$errors): ?array
    {
        $name = $this->stringValue($row, $headerMap, ProductImportSchema::COL_NAME);
        if ($name === null) {
            $errors[] = 'kolom Nama Produk wajib diisi.';

            return null;
        }
        if (mb_strlen($name) > 255) {
            $errors[] = 'Nama Produk melebihi 255 karakter.';

            return null;
        }

        $sku = $this->stringValue($row, $headerMap, ProductImportSchema::COL_SKU);
        $barcode = $this->stringValue($row, $headerMap, ProductImportSchema::COL_BARCODE);

        if ($sku !== null && mb_strlen($sku) > 255) {
            $errors[] = 'SKU melebihi 255 karakter.';
        }
        if ($barcode !== null && mb_strlen($barcode) > 255) {
            $errors[] = 'Barcode melebihi 255 karakter.';
        }

        if ($sku !== null && ! $this->markOnce($context, 'sku', mb_strtolower($sku))) {
            $errors[] = "SKU «{$sku}» dipakai lebih dari satu kali di file ini.";
        }
        if ($barcode !== null && ! $this->markOnce($context, 'barcode', mb_strtolower($barcode))) {
            $errors[] = "Barcode «{$barcode}» dipakai lebih dari satu kali di file ini.";
        }
        if ($sku === null && $barcode === null && ! $this->markOnce($context, 'name', mb_strtolower($name))) {
            $errors[] = "Nama produk «{$name}» tanpa SKU/Barcode muncul lebih dari satu kali di file ini.";
        }

        $product = $this->matchProductFromIndex($storeProductIndex, $sku, $barcode, $name);

        if ($product !== null && ! $this->markOnce($context, 'product', (string) $product->id)) {
            $errors[] = "Beberapa baris mengarah ke produk yang sama («{$product->name}»). Gabungkan menjadi satu baris.";
        }

        if ($product !== null && $product->has_variants) {
            $errors[] = "Produk «{$product->name}» memiliki varian sehingga tidak dapat diperbarui lewat impor. Ubah melalui halaman Edit Produk.";
        }
        if ($product !== null && $product->is_bundle) {
            $errors[] = "Produk «{$product->name}» adalah produk paket sehingga tidak dapat diperbarui lewat impor. Ubah melalui halaman Edit Produk.";
        }

        if ($sku !== null && $this->conflictsWithOtherProductIndex($storeProductIndex, 'sku', $sku, $product?->id)) {
            $errors[] = "SKU «{$sku}» sudah dipakai produk lain di toko ini.";
        }
        if ($barcode !== null && $this->conflictsWithOtherProductIndex($storeProductIndex, 'barcode', $barcode, $product?->id)) {
            $errors[] = "Barcode «{$barcode}» sudah dipakai produk lain di toko ini.";
        }

        $isNew = $product === null;

        $price = $this->numberValue($row, $headerMap, ProductImportSchema::COL_PRICE, 'Harga Jual', $errors);
        if ($price !== null && $price < 0) {
            $errors[] = 'Harga Jual tidak boleh negatif.';
        }

        $cost = $this->numberValue($row, $headerMap, ProductImportSchema::COL_COST, 'Harga Beli', $errors);
        if ($cost !== null && $cost < 0) {
            $errors[] = 'Harga Beli tidak boleh negatif.';
        }

        $stock = $this->numberValue($row, $headerMap, ProductImportSchema::COL_STOCK, 'Stok', $errors);
        if ($stock !== null && $stock < 0) {
            $errors[] = 'Stok tidak boleh negatif.';
        }

        $minStock = $this->numberValue($row, $headerMap, ProductImportSchema::COL_MIN_STOCK, 'Stok Minimum', $errors);
        if ($minStock !== null && $minStock < 0) {
            $errors[] = 'Stok Minimum tidak boleh negatif.';
        }

        $tax = $this->numberValue($row, $headerMap, ProductImportSchema::COL_TAX, 'Pajak (%)', $errors);
        if ($tax !== null && ($tax < 0 || $tax > 100)) {
            $errors[] = 'Pajak (%) harus berada di antara 0 dan 100.';
        }

        $inventoryType = $this->choiceValue(
            $row,
            $headerMap,
            ProductImportSchema::COL_INVENTORY_TYPE,
            ProductImportSchema::inventoryTypeMap(),
            'Tipe Inventori',
            ProductImportSchema::inventoryTypeChoices(),
            $errors,
        ) ?? ($product !== null ? InventoryType::of($product) : InventoryType::SELLABLE);

        $baseUnit = $this->choiceValue(
            $row,
            $headerMap,
            ProductImportSchema::COL_BASE_UNIT,
            ProductImportSchema::baseUnitMap(),
            'Satuan Dasar',
            ProductImportSchema::baseUnitChoices(),
            $errors,
        ) ?? ($product !== null ? $product->baseUnit() : BaseUnit::PIECE);

        if ($product !== null && $baseUnit !== $product->baseUnit()) {
            if (ProductSaleConsumption::query()->where('component_product_id', $product->id)->exists()) {
                $errors[] = 'Satuan Dasar tidak dapat diubah karena produk dipakai pada konsumsi saat penjualan.';
            } elseif (ProductQuantity::cmp((string) $product->stock_quantity, '0.0000') !== 0) {
                $errors[] = 'Satuan Dasar tidak dapat diubah selama produk masih memiliki stok.';
            }
        }

        $trackStock = $this->booleanValue($row, $headerMap, ProductImportSchema::COL_TRACK_STOCK, 'Lacak Stok', $product !== null ? (bool) $product->track_stock : true, $errors);
        $unlimitedStock = $this->booleanValue($row, $headerMap, ProductImportSchema::COL_UNLIMITED_STOCK, 'Stok Tanpa Batas', $product !== null ? (bool) $product->unlimited_stock : false, $errors);
        $isActive = $this->booleanValue($row, $headerMap, ProductImportSchema::COL_IS_ACTIVE, 'Status Aktif', $product !== null ? (bool) $product->is_active : true, $errors);
        $allowsDecimal = $this->booleanValue($row, $headerMap, ProductImportSchema::COL_ALLOWS_DECIMAL, 'Izinkan Desimal', $product !== null ? (bool) $product->allows_decimal : false, $errors);

        // Paket Starter: produk baru tanpa desimal; produk lama mempertahankan nilai tersimpan.
        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            $allowsDecimal = $product !== null ? (bool) $product->allows_decimal : false;
        }

        $unit = $this->stringValue($row, $headerMap, ProductImportSchema::COL_UNIT)
            ?? ($product?->unit ?? 'pcs');
        if (mb_strlen($unit) > 50) {
            $errors[] = 'Satuan melebihi 50 karakter.';
        }

        $brand = $this->stringValue($row, $headerMap, ProductImportSchema::COL_BRAND);
        if ($brand !== null && mb_strlen($brand) > 255) {
            $errors[] = 'Merek melebihi 255 karakter.';
        }

        $categoryName = $this->stringValue($row, $headerMap, ProductImportSchema::COL_CATEGORY);
        if ($categoryName !== null && mb_strlen($categoryName) > 100) {
            $errors[] = 'Kategori melebihi 100 karakter.';
        }

        $description = $this->stringValue($row, $headerMap, ProductImportSchema::COL_DESCRIPTION);

        $imageUrl = $this->stringValue($row, $headerMap, ProductImportSchema::COL_IMAGE_URL);
        if ($imageUrl !== null && ! preg_match('#^https?://#i', $imageUrl)) {
            $errors[] = 'URL Gambar harus berupa alamat http/https.';
        }

        $pharmacyClass = null;
        $pharmacyRegNo = null;
        if (PharmacyFeatures::enabled($store)) {
            $pharmacyClass = $this->choiceValue(
                $row,
                $headerMap,
                ProductImportSchema::COL_PHARMACY_CLASS,
                ProductImportSchema::pharmacyClassMap(),
                'Kelas Penjualan Obat',
                'otc, limited, prescription',
                $errors,
            ) ?? $product?->pharmacy_sale_class;

            if ($pharmacyClass === null) {
                $errors[] = 'kolom Kelas Penjualan Obat wajib diisi (otc, limited, atau prescription).';
            }

            $pharmacyRegNo = $this->stringValue($row, $headerMap, ProductImportSchema::COL_PHARMACY_REG_NO) ?? $product?->pharmacy_registration_no;
            if ($pharmacyRegNo !== null && mb_strlen($pharmacyRegNo) > 128) {
                $errors[] = 'No. Registrasi Obat melebihi 128 karakter.';
            }
        }

        $qtyContext = new Product(['allows_decimal' => $allowsDecimal]);
        $stockValue = $stock !== null
            ? ProductQuantity::normalizeStorageAmount($qtyContext, $stock)
            : ($product !== null ? ProductQuantity::normalizeStorageAmount($qtyContext, $product->stock_quantity) : '0');
        $minStockValue = $minStock !== null
            ? ProductQuantity::normalizeStorageAmount($qtyContext, $minStock)
            : ($product !== null ? ProductQuantity::normalizeStorageAmount($qtyContext, $product->min_stock_level) : '0');

        $batchCode = null;
        $expiryDate = null;

        if (BatchTracking::enabled($store)) {
            $batchCode = $this->stringValue($row, $headerMap, ProductImportSchema::COL_BATCH_CODE);
            $rawExpiry = $this->rawValue($row, $headerMap, ProductImportSchema::COL_EXPIRY_DATE);
            $expiryDate = $this->parseExpiryDate($rawExpiry, $errors);

            if ($product !== null && $stock !== null && ProductQuantity::cmp($stockValue, (string) $product->stock_quantity) !== 0) {
                $errors[] = 'Mode batch & ED aktif: stok produk lama tidak dapat diubah lewat impor. Sesuaikan melalui halaman produk.';
            }

            if ($product === null && $trackStock && PharmacyFeatures::enabled($store)) {
                $hasStock = ProductQuantity::cmp($stockValue, '0.0000') > 0;
                if ($hasStock && $expiryDate === null) {
                    $errors[] = 'Apotek: kolom Tanggal Kedaluwarsa wajib diisi bila stok awal lebih dari 0.';
                }
            }
        }

        if ($errors !== []) {
            return null;
        }

        return [
            'product' => $product,
            'category_name' => $categoryName,
            'batch_code' => $batchCode,
            'expiry_date' => $expiryDate,
            'payload' => [
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'description' => $description ?? $product?->description,
                'price' => $price ?? ($product !== null ? (float) $product->price : 0.0),
                'cost_price' => $cost ?? ($product?->cost_price !== null ? (float) $product->cost_price : null),
                'stock_quantity' => $stockValue,
                'min_stock_level' => $minStockValue,
                'brand' => $brand ?? $product?->brand,
                'unit' => $unit,
                'inventory_type' => $inventoryType,
                'base_unit' => $baseUnit,
                'tax_rate' => $tax ?? ($product !== null ? (float) $product->tax_rate : 0.0),
                'track_stock' => $trackStock,
                'unlimited_stock' => $unlimitedStock,
                'allows_decimal' => $allowsDecimal,
                'is_active' => $isActive,
                'pharmacy_sale_class' => $pharmacyClass,
                'pharmacy_registration_no' => $pharmacyRegNo,
            ],
            'cost_provided' => $cost !== null,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createProduct(Store $store, array $item, ?int $userId): void
    {
        $payload = $item['payload'];
        $payload['product_category_id'] = $this->resolveCategoryId($store, $item['category_name']);
        $payload['category'] = $item['category_name'];
        $payload['average_cost'] = $payload['cost_price'];
        $payload['has_variants'] = false;
        $payload['is_bundle'] = false;

        if (! PharmacyFeatures::enabled($store)) {
            unset($payload['pharmacy_sale_class'], $payload['pharmacy_registration_no']);
        }

        /** @var Product $product */
        $product = $store->products()->create($payload);

        $this->applyImageUrl($product, $item['image_url']);

        if (BatchTracking::enabled($store) && $product->track_stock) {
            $hasStock = ProductQuantity::cmp((string) $product->stock_quantity, '0.0000') > 0;
            if ($hasStock || $item['batch_code'] !== null || $item['expiry_date'] !== null) {
                ProductBatch::create([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'quantity_remaining' => $product->stock_quantity,
                    'expiry_date' => $item['expiry_date'],
                    'batch_code' => $item['batch_code'],
                    'status' => ProductBatch::STATUS_AVAILABLE,
                ]);
            }
        }

        if ($product->track_stock && ProductQuantity::cmp((string) $product->stock_quantity, '0.0000') > 0) {
            StockMovement::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'quantity_change' => (string) $product->stock_quantity,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'balance_after' => (string) $product->stock_quantity,
                'notes' => 'Stok awal (impor produk)',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function updateProduct(Store $store, Product $product, array $item, ?int $userId): void
    {
        $payload = $item['payload'];
        $oldStock = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);

        if ($item['category_name'] !== null) {
            $payload['product_category_id'] = $this->resolveCategoryId($store, $item['category_name']);
            $payload['category'] = $item['category_name'];
        }

        if ($item['cost_provided']) {
            $payload['average_cost'] = $payload['cost_price'];
        } else {
            unset($payload['cost_price']);
        }

        if (! PharmacyFeatures::enabled($store)) {
            unset($payload['pharmacy_sale_class'], $payload['pharmacy_registration_no']);
        }

        $product->update($payload);

        $this->applyImageUrl($product, $item['image_url']);

        $newStock = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);
        $delta = bcsub($newStock, $oldStock, ProductQuantity::SCALE);

        if ($product->track_stock && bccomp($delta, '0.0000', ProductQuantity::SCALE) !== 0) {
            StockMovement::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'quantity_change' => $delta,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'balance_after' => $newStock,
                'notes' => 'Penyesuaian stok dari impor produk',
            ]);
        }
    }

    /**
     * Unduh gambar dari URL lalu simpan sebagai gambar pertama produk.
     * Kegagalan unduh tidak menggagalkan impor; gambar lama dipertahankan.
     */
    private function applyImageUrl(Product $product, ?string $url): void
    {
        if ($url === null) {
            return;
        }

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return;
            }

            $body = (string) $response->body();
            if ($body === '') {
                return;
            }

            $path = 'products/'.Str::uuid().'.'.$this->imageExtension((string) $response->header('Content-Type'), $url);
            Storage::disk('public')->put($path, $body);

            $product->update(['images' => [$path]]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function imageExtension(string $contentType, string $url): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $mime = strtolower(trim(explode(';', $contentType)[0]));
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
            ? ($ext === 'jpeg' ? 'jpg' : $ext)
            : 'jpg';
    }

    private function resolveCategoryId(Store $store, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        $key = mb_strtolower($name);
        if (isset($this->categoryIdCache[$store->id][$key])) {
            return $this->categoryIdCache[$store->id][$key];
        }

        $category = ProductCategory::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [$key])
            ->first();

        if ($category === null) {
            $category = ProductCategory::query()->create([
                'store_id' => $store->id,
                'name' => $name,
                'sort_order' => 0,
            ]);
        }

        return $this->categoryIdCache[$store->id][$key] = (int) $category->id;
    }

    /**
     * Tandai nilai unik lintas baris; mengembalikan false bila nilai sudah pernah dipakai.
     *
     * @param  array<string, array<string, true>>  $context
     */
    private function markOnce(array &$context, string $bucket, string $value): bool
    {
        if (isset($context[$bucket][$value])) {
            return false;
        }

        $context[$bucket][$value] = true;

        return true;
    }

    /**
     * Pencocokan baris ke produk lama via in-memory index.
     *
     * Perilaku harus identik dengan query lama:
     * - Bila SKU diisi, hanya dicocokkan berdasarkan SKU; bila tidak ditemukan, baris
     *   dianggap produk baru (tidak fallback ke barcode/nama).
     * - Tanpa SKU, dicocokkan berdasarkan Barcode bila diisi, lalu Nama Produk.
     *
     * @param  array{bySku: array<string, Product>, byBarcode: array<string, Product>, byName: array<string, Product>}  $index
     */
    private function matchProductFromIndex(array $index, ?string $sku, ?string $barcode, string $name): ?Product
    {
        if ($sku !== null) {
            return $index['bySku'][mb_strtolower($sku)] ?? null;
        }

        if ($barcode !== null) {
            return $index['byBarcode'][mb_strtolower($barcode)] ?? null;
        }

        return $index['byName'][mb_strtolower($name)] ?? null;
    }

    /**
     * Cek konflik dengan produk lain via in-memory index.
     *
     * @param  array{bySku: array<string, Product>, byBarcode: array<string, Product>, byName: array<string, Product>}  $index
     */
    private function conflictsWithOtherProductIndex(array $index, string $column, string $value, ?int $ignoreId): bool
    {
        $bucket = $column === 'sku' ? 'bySku' : 'byBarcode';
        $existing = $index[$bucket][mb_strtolower($value)] ?? null;

        return $existing !== null && (int) $existing->id !== (int) $ignoreId;
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int>
     */
    private function buildHeaderMap(mixed $header): array
    {
        if (! is_array($header)) {
            return [];
        }

        $map = [];
        foreach (array_values($header) as $index => $cell) {
            $key = ProductImportSchema::normalizeHeader($cell);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     */
    private function rawValue(array $row, array $headerMap, string $key): mixed
    {
        if (! isset($headerMap[$key])) {
            return null;
        }

        return $row[$headerMap[$key]] ?? null;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     */
    private function stringValue(array $row, array $headerMap, string $key): ?string
    {
        $raw = $this->rawValue($row, $headerMap, $key);

        if ($raw === null || is_array($raw)) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $errors
     */
    private function numberValue(array $row, array $headerMap, string $key, string $label, array &$errors): ?float
    {
        $raw = $this->rawValue($row, $headerMap, $key);

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $value = ProductImportSchema::parseNumber($raw);
        if ($value === null) {
            $errors[] = "kolom {$label} harus berupa angka.";

            return null;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $errors
     */
    private function booleanValue(array $row, array $headerMap, string $key, string $label, bool $default, array &$errors): bool
    {
        $raw = $this->rawValue($row, $headerMap, $key);
        $value = ProductImportSchema::parseBoolean($raw, $default);

        if ($value === null) {
            $errors[] = "kolom {$label} hanya menerima ya atau tidak.";

            return $default;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     * @param  array<string, string>  $choices
     * @param  list<string>  $errors
     */
    private function choiceValue(array $row, array $headerMap, string $key, array $choices, string $label, string $allowed, array &$errors): ?string
    {
        $raw = $this->stringValue($row, $headerMap, $key);
        if ($raw === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));
        if (! isset($choices[$normalized])) {
            $errors[] = "kolom {$label} berisi nilai «{$raw}» yang tidak dikenal. Pilihan: {$allowed}.";

            return null;
        }

        return $choices[$normalized];
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseExpiryDate(mixed $raw, array &$errors): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance($raw)->format('Y-m-d');
        }

        if (is_numeric($raw)) {
            // Angka serial tanggal Excel.
            try {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)
                )->format('Y-m-d');
            } catch (\Throwable) {
                $errors[] = 'kolom Tanggal Kedaluwarsa tidak dikenali. Gunakan format YYYY-MM-DD.';

                return null;
            }
        }

        try {
            return Carbon::parse(trim((string) $raw))->format('Y-m-d');
        } catch (\Throwable) {
            $errors[] = 'kolom Tanggal Kedaluwarsa tidak dikenali. Gunakan format YYYY-MM-DD.';

            return null;
        }
    }

    /**
     * Sortir baris: non-sellable (bahan baku, kemasan, hasil produksi) diimpor duluan
     * supaya data bahan baku sudah ada di DB saat produk jadi diproses.
     *
     * @param  array<int, list<mixed>>  $dataRows
     * @return array<int, list<mixed>>
     */
    private function sortRowsByInventoryType(array $dataRows, array $headerMap): array
    {
        $sellableTypes = [
            InventoryType::SELLABLE,
        ];

        $nonSellableRows = [];
        $sellableRows = [];

        foreach ($dataRows as $line => $row) {
            $inventoryTypeRaw = $this->stringValue($row, $headerMap, ProductImportSchema::COL_INVENTORY_TYPE);
            $normalizedType = $inventoryTypeRaw !== null
                ? InventoryType::normalize($inventoryTypeRaw)
                : InventoryType::SELLABLE;

            if (in_array($normalizedType, $sellableTypes, true)) {
                $sellableRows[$line] = $row;
            } else {
                $nonSellableRows[$line] = $row;
            }
        }

        // Non-sellable duluan, sellable belakangan; pertahankan nomor baris asli.
        $sorted = [];
        foreach ($nonSellableRows as $line => $row) {
            $sorted[$line] = $row;
        }
        foreach ($sellableRows as $line => $row) {
            $sorted[$line] = $row;
        }

        return $sorted;
    }

    private function isEmptyRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return true;
        }

        foreach ($row as $cell) {
            if ($cell === null) {
                continue;
            }
            if (is_string($cell) && trim($cell) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    private function failure(string $message): array
    {
        return ['created' => 0, 'updated' => 0, 'errors' => [$message]];
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function capErrors(array $errors): array
    {
        if (count($errors) <= self::MAX_REPORTED_ERRORS) {
            return $errors;
        }

        $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
        $shown[] = 'dan '.(count($errors) - self::MAX_REPORTED_ERRORS).' kesalahan lain.';

        return $shown;
    }
}
