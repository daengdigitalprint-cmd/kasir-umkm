<?php

namespace App\Http\Controllers;

use App\Exports\ProductImportTemplateExport;
use App\Exports\ProductsExport;
use App\Exports\ProductsWithComponentsExport;
use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBundleItem;
use App\Models\ProductCategory;
use App\Models\ProductSaleConsumption;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\ProductImportService;
use App\Support\BarbershopFeatures;
use App\Support\BarbershopSampleServices;
use App\Support\BaseUnit;
use App\Support\BatchTracking;
use App\Support\BusinessTypePresets;
use App\Support\CacheKeys;
use App\Support\InventoryType;
use App\Support\ModifierOptions;
use App\Support\PharmacyFeatures;
use App\Support\ProductQuantity;
use App\Support\StockAdjustmentApproval;
use App\Support\StoreDefaultProductCategories;
use App\Support\StorePlanLimits;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $search = $request->string('search')->trim()->toString();
        $inventoryType = $request->string('inventory_type')->trim()->toString();
        $categoryFilter = $request->string('category')->trim()->toString();

        $productsQuery = $store->products()
            ->with('store')
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')]);

        if ($search !== '') {
            $productsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($inventoryType !== '' && InventoryType::isValid($inventoryType)) {
            $productsQuery->where('inventory_type', $inventoryType);
        }

        if ($categoryFilter !== '') {
            $productsQuery->where(function ($q) use ($categoryFilter) {
                $q->where('category', $categoryFilter)
                    ->orWhere('product_category_id', function ($sub) use ($categoryFilter) {
                        $sub->select('id')
                            ->from('product_categories')
                            ->whereColumn('product_categories.id', 'products.product_category_id')
                            ->where('name', $categoryFilter);
                    });
            });
        }

        $products = $productsQuery->latest()->paginate(20)->withQueryString();

        $products->getCollection()->transform(function ($product) {
            $product->image_url = $product->getImageUrlAttribute();

            return $product;
        });

        // Daftar kategori unik yang dipakai di toko ini (cached — daftar master)
        $categories = Cache::remember(
            CacheKeys::productCategories((int) $store->id),
            CacheKeys::MASTER_TTL_SECONDS,
            fn () => $store->products()
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->pluck('category')
                ->sort()
                ->values()
                ->all()
        );

        // Jumlah produk stok rendah di seluruh toko (konsisten dengan dashboard)
        $lowStockCount = $store->products()
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->count();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'store' => $store,
            'categories' => $categories,
            'inventoryTypes' => InventoryType::LABELS,
            'lowStockCount' => $lowStockCount,
            'filters' => [
                'search' => $search,
                'inventory_type' => $inventoryType,
                'category' => $categoryFilter,
            ],
        ]);
    }

    /** Unduh seluruh produk toko sebagai file Excel yang formatnya sama dengan template impor. */
    public function exportExcel()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $filename = 'produk-'.Str::slug($store->name ?: 'toko').'-'.now()->format('Ymd-His').'.xlsx';

        try {
            return Excel::download(new ProductsExport($store), $filename, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('products.index')
                ->with('error', 'Gagal membuat file ekspor produk. Coba lagi beberapa saat lagi.');
        }
    }

    /** Unduh produk plus komponen (recipe & consumption) untuk backup/restore lengkap. */
    public function exportWithComponents()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $filename = 'produk-komponen-'.Str::slug($store->name ?: 'toko').'-'.now()->format('Ymd-His').'.xlsx';

        try {
            return Excel::download(new ProductsWithComponentsExport($store), $filename, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('products.index')
                ->with('error', 'Gagal membuat file ekspor produk dengan komponen. Coba lagi beberapa saat lagi.');
        }
    }

    /** Unduh template impor produk beserta sheet panduan pengisian. */
    public function importTemplate()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $filename = 'template-impor-produk-'.Str::slug($store->name ?: 'toko').'.xlsx';

        try {
            return Excel::download(new ProductImportTemplateExport($store), $filename, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('products.index')
                ->with('error', 'Gagal membuat template impor produk. Coba lagi beberapa saat lagi.');
        }
    }

    /** Proses file impor produk: menambah produk baru dan memperbarui produk yang sudah ada. */
    public function importStore(Request $request, ProductImportService $service)
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Pilih file impor terlebih dahulu.',
            'file.mimes' => 'Format file harus .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);

        try {
            $importer = new ProductsImport;
            $rows = $importer->rowsFrom($request->file('file'));
            $variantRows = $importer->variantRowsFrom($request->file('file'));
            $componentRows = $importer->componentRowsFrom($request->file('file'));
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'file' => 'File tidak dapat dibaca. Pastikan file berformat .xlsx, .xls, atau .csv dan tidak rusak.',
            ]);
        }

        $variantImported = 0;
        $componentImported = 0;

        try {
            $result = $service->import($store, $rows, auth()->id());

            // Urutan penting: varian dulu (komponen menu bisa menunjuk varian),
            // lalu komponen paket, komponen menu, dan resep produksi.
            if ($result['errors'] === []) {
                $variantResult = $service->importVariants($store, $variantRows, auth()->id());
                $variantImported = $variantResult['imported'];
                $result['errors'] = $variantResult['errors'];
            }

            if ($result['errors'] === []) {
                $bundleResult = $service->importBundleItems($store, $componentRows, auth()->id());
                $componentImported += $bundleResult['imported'];
                $result['errors'] = $bundleResult['errors'];
            }

            if ($result['errors'] === []) {
                $consumptionResult = $service->importSaleConsumptions($store, $componentRows, auth()->id());
                $componentImported += $consumptionResult['imported'];
                $result['errors'] = $consumptionResult['errors'];
            }

            if ($result['errors'] === []) {
                $recipeResult = $service->importRecipes($store, $componentRows, auth()->id());
                $componentImported += $recipeResult['imported'];
                $result['errors'] = $recipeResult['errors'];
            }
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'file' => 'Impor gagal diproses sehingga tidak ada data yang diubah. Periksa isi file lalu coba lagi.',
            ]);
        }

        if ($result['errors'] !== []) {
            // Inertia hanya mengirim satu pesan per field, jadi seluruh daftar digabung
            // menjadi satu teks multi-baris dan dipecah kembali di halaman produk.
            throw ValidationException::withMessages([
                'file' => implode("\n", $result['errors']),
            ]);
        }

        $parts = [];
        if ($result['created'] > 0) {
            $parts[] = $result['created'].' produk baru';
        }
        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' produk diperbarui';
        }
        if ($variantImported > 0) {
            $parts[] = $variantImported.' varian diimpor';
        }
        if ($componentImported > 0) {
            $parts[] = $componentImported.' komponen diimpor';
        }

        return redirect()->route('products.index')
            ->with('success', 'Impor produk berhasil: '.implode(', ', $parts).'.');
    }

    public function create()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }
        $bundleCatalogProducts = $this->bundleCatalogProductsForStore($store, null);
        $saleConsumptionCatalog = $this->saleConsumptionCatalogForStore($store, null);

        $productCategories = $this->productCategoriesQuery($store)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        return Inertia::render('Products/Create', [
            'store' => $store,
            'productCategories' => $productCategories,
            'catalogPresets' => BusinessTypePresets::catalog($store->business_type ?? 'general'),
            'inventoryTypes' => InventoryType::LABELS,
            'baseUnits' => BaseUnit::LABELS,
            'batchTrackingEnabled' => BatchTracking::enabled($store),
            'fnbModifierEnabled' => ($store->business_type ?? '') === 'fnb',
            'pharmacyFieldsEnabled' => PharmacyFeatures::enabled($store),
            'barbershopEnabled' => BarbershopFeatures::enabled($store),
            'bundleCatalogProducts' => $bundleCatalogProducts,
            'saleConsumptionCatalog' => $saleConsumptionCatalog,
        ]);
    }

    /**
     * Membuat produk layanan contoh untuk toko barbershop (idempotent per nama).
     */
    public function sampleServices(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! BarbershopFeatures::enabled($store)) {
            return back()->with('error', 'Layanan contoh hanya tersedia untuk toko barbershop.');
        }

        StoreDefaultProductCategories::ensureForStore($store);

        $categoryIds = $store->productCategories()
            ->pluck('id', 'name');

        $created = 0;
        $skipped = 0;

        foreach (BarbershopSampleServices::all() as $service) {
            $exists = $store->products()
                ->where('name', $service['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $store->products()->create([
                'name' => $service['name'],
                'category' => $service['category'],
                'product_category_id' => $categoryIds[$service['category']] ?? null,
                'price' => $service['price'],
                'unit' => 'jasa',
                'inventory_type' => InventoryType::normalize('sellable'),
                'track_stock' => false,
                'stock_quantity' => 0,
                'min_stock_level' => 0,
                'tax_rate' => 0,
                'is_active' => true,
                'service_duration_minutes' => $service['duration'],
            ]);

            $created++;
        }

        return back()->with('success', sprintf(
            'Layanan contoh ditambahkan: %d baru, %d sudah ada sebelumnya.',
            $created,
            $skipped,
        ));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $request->merge([
            'track_stock' => $request->boolean('track_stock'),
            'unlimited_stock' => $request->boolean('unlimited_stock'),
            'allow_negative_stock' => $request->boolean('allow_negative_stock'),
            'allows_decimal' => $request->boolean('allows_decimal'),
            'has_variants' => $request->boolean('has_variants'),
            'is_bundle' => $request->boolean('is_bundle'),
            'product_category_id' => $request->filled('product_category_id')
                ? (int) $request->input('product_category_id')
                : null,
        ]);

        $rawTax = $request->input('tax_rate');
        if ($rawTax !== null && $rawTax !== '' && ! is_numeric($rawTax)) {
            $request->merge(['tax_rate' => null]);
        }

        $validated = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255|unique:products,sku,NULL,id,store_id,'.$store->id,
            'barcode' => 'nullable|string|max:255|unique:products,barcode,NULL,id,store_id,'.$store->id,
            'description' => 'nullable|string',
            'price' => [Rule::requiredIf(! $request->boolean('has_variants')), 'nullable', 'numeric', 'min:0'],
            'wholesale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
            'product_category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where('store_id', $store->id),
            ],
            'brand' => 'nullable|string|max:255',
            'unit' => 'required|string|max:50',
            'inventory_type' => 'nullable|string|in:'.implode(',', array_keys(InventoryType::LABELS)),
            'base_unit' => 'nullable|string|in:'.implode(',', array_keys(BaseUnit::LABELS)),
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'track_stock' => 'boolean',
            'unlimited_stock' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'allows_decimal' => 'boolean',
            'has_variants' => 'boolean',
            'is_bundle' => 'boolean',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'batches_json' => 'nullable|string|max:65500',
            'variants_json' => 'nullable|string|max:65500',
            'bundles_json' => 'nullable|string|max:65500',
            'sale_consumptions_json' => 'nullable|string|max:65500',
            'modifier_options_json' => 'nullable|string|max:65535',
            'service_duration_minutes' => 'nullable|integer|min:1|max:1440',
            'barber_commission_mode' => 'sometimes|nullable|string|in:percent,fixed',
            'barber_commission_value' => 'sometimes|nullable|numeric|min:0|max:100000000',
        ], $this->pharmacyValidationRules($store)));

        $allowsDecimal = (bool) ($validated['allows_decimal'] ?? false);
        $hasVariants = (bool) ($validated['has_variants'] ?? false);
        $isBundle = (bool) ($validated['is_bundle'] ?? false);
        $this->lockProductRetailOptionsForPlan($store, null, $allowsDecimal, $hasVariants, $isBundle);
        $qtyRules = new Product(['allows_decimal' => $allowsDecimal]);

        if ($isBundle && $hasVariants) {
            throw ValidationException::withMessages([
                'is_bundle' => 'Produk paket tidak bisa dikombinasikan dengan varian.',
            ]);
        }

        if ($hasVariants && BatchTracking::enabled($store)) {
            throw ValidationException::withMessages([
                'has_variants' => 'Produk dengan varian belum mendukung mode batch & ED. Nonaktifkan batch di pengaturan toko atau buat produk tanpa varian.',
            ]);
        }

        if ($hasVariants) {
            $vRows = $this->decodeVariantsJson($request->input('variants_json'));
            if ($vRows === null || count($vRows) < 1) {
                throw ValidationException::withMessages([
                    'variants_json' => 'Tambahkan minimal satu varian.',
                ]);
            }
        }

        if ($isBundle) {
            $bRows = $this->decodeBundlesJson($request->input('bundles_json'));
            if ($bRows === null || count($bRows) < 1) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Tambahkan minimal satu komponen paket.',
                ]);
            }
        }

        StorePlanLimits::assertCanCreateProduct($store);

        $categoryFields = $this->categoryFieldsForStore($store, $validated['product_category_id'] ?? null);

        $saleConsumptionRows = $this->decodeSaleConsumptionsJson($request->input('sale_consumptions_json')) ?? [];
        if ($hasVariants && $saleConsumptionRows !== []) {
            throw ValidationException::withMessages([
                'sale_consumptions_json' => 'Konsumsi produk bervarian dapat diatur setelah produk dibuat melalui halaman Edit.',
            ]);
        }
        $saleConsumptionTarget = new Product([
            'store_id' => $store->id,
            'inventory_type' => InventoryType::normalize($validated['inventory_type'] ?? null),
            'is_bundle' => $isBundle,
            'has_variants' => $hasVariants,
        ]);
        $normalizedSaleConsumptions = $this->normalizeSaleConsumptions($saleConsumptionTarget, $store, $saleConsumptionRows);

        $initialCost = isset($validated['cost_price']) && is_numeric($validated['cost_price'])
            ? (float) $validated['cost_price']
            : null;
        if ($this->hasProductionOutputConsumption($normalizedSaleConsumptions)) {
            $initialCost = null;
        }

        $createPayload = [
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'description' => $validated['description'] ?? null,
            'price' => isset($validated['price']) && is_numeric($validated['price']) ? (float) $validated['price'] : 0,
            'wholesale_price' => ($store->settings['wholesale_enabled'] ?? false)
                && isset($validated['wholesale_price']) && is_numeric($validated['wholesale_price']) && (float) $validated['wholesale_price'] > 0
                ? (float) $validated['wholesale_price']
                : null,
            'cost_price' => $initialCost,
            'average_cost' => $initialCost,
            'stock_quantity' => ($hasVariants || $isBundle)
                ? '0.0000'
                : ProductQuantity::normalizeStorageAmount($qtyRules, $validated['stock_quantity']),
            'min_stock_level' => ($hasVariants || $isBundle)
                ? '0.0000'
                : ProductQuantity::normalizeStorageAmount($qtyRules, $validated['min_stock_level']),
            'product_category_id' => $categoryFields['product_category_id'],
            'category' => $categoryFields['category'],
            'brand' => $validated['brand'] ?? null,
            'unit' => $validated['unit'],
            'inventory_type' => InventoryType::normalize($validated['inventory_type'] ?? null),
            'base_unit' => BaseUnit::normalizeBaseUnit($validated['base_unit'] ?? null),
            'tax_rate' => isset($validated['tax_rate']) && is_numeric($validated['tax_rate'])
                ? (float) $validated['tax_rate']
                : 0,
            'track_stock' => $isBundle ? false : (bool) ($validated['track_stock'] ?? false),
            'unlimited_stock' => $isBundle ? false : (bool) ($validated['unlimited_stock'] ?? false),
            'allow_negative_stock' => $isBundle ? false : (bool) ($validated['allow_negative_stock'] ?? false),
            'allows_decimal' => $allowsDecimal,
            'has_variants' => $hasVariants && ! $isBundle,
            'is_bundle' => $isBundle,
            'is_active' => true,
        ];
        $createPayload = array_merge($createPayload, $this->pharmacyFieldsForPayload($store, $validated));
        if (BarbershopFeatures::enabled($store)) {
            $createPayload['service_duration_minutes'] = $validated['service_duration_minutes'] ?? null;
            $createPayload['barber_commission_mode'] = $validated['barber_commission_mode'] ?? null;
            $createPayload['barber_commission_value'] = $validated['barber_commission_value'] ?? null;
        }
        if (($store->business_type ?? '') === 'fnb') {
            $createPayload['modifier_options'] = $isBundle
                ? []
                : $this->decodeModifierOptionsJson($request->input('modifier_options_json'));
        }

        $product = $store->products()->create($createPayload);

        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $path = $image->store('products', 'public');
                $imagePaths[] = $path;
            }
            $product->update(['images' => $imagePaths]);
        }

        if ($product->has_variants) {
            ProductBundleItem::where('bundle_product_id', $product->id)->delete();
            ProductBatch::where('product_id', $product->id)->delete();
            $vDecoded = $this->decodeVariantsJson($request->input('variants_json'));
            $this->replaceProductVariants($product, $store, $vDecoded ?? [], $qtyRules);
        } elseif ($product->is_bundle) {
            ProductBatch::where('product_id', $product->id)->delete();
            ProductVariant::where('product_id', $product->id)->delete();
            $bDecoded = $this->decodeBundlesJson($request->input('bundles_json'));
            $this->replaceProductBundleItems($product, $store, $bDecoded ?? []);
        } elseif (BatchTracking::enabled($store) && $product->track_stock) {
            $rows = $this->decodeBatchesJson($request->input('batches_json'));
            if ($rows === null) {
                if (PharmacyFeatures::enabled($store)) {
                    throw ValidationException::withMessages([
                        'batches_json' => 'Untuk apotek, susunan batch wajib diisi (minimal satu batch, ED wajib per baris).',
                    ]);
                }
                $rows = [[
                    'quantity_remaining' => $product->stock_quantity,
                    'expiry_date' => null,
                    'batch_code' => null,
                ]];
            }
            $this->assertPharmacyBatchExpiryRules($store, $rows);
            $this->replaceProductBatches($product, $rows);
            $sum = ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $sum)]);
        }

        if (! $product->has_variants && ! $product->is_bundle) {
            $this->replaceSaleConsumptions($product, $normalizedSaleConsumptions);
        }

        if ($product->track_stock && $product->has_variants) {
            $product->load('variants');
            foreach ($product->variants as $v) {
                if (ProductQuantity::cmp((string) $v->stock_quantity, '0.0000') > 0) {
                    $bal = (string) $v->stock_quantity;
                    StockMovement::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $v->id,
                        'user_id' => auth()->id(),
                        'quantity_change' => $bal,
                        'type' => StockMovement::TYPE_ADJUSTMENT,
                        'balance_after' => $bal,
                        'notes' => 'Stok awal varian (produk baru)',
                    ]);
                }
            }
        } elseif ($product->track_stock && ProductQuantity::cmp((string) $product->stock_quantity, '0.0000') > 0) {
            $bal = (string) $product->stock_quantity;
            StockMovement::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'quantity_change' => $bal,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'balance_after' => $bal,
                'notes' => 'Stok awal (produk baru)',
            ]);
        }

        if ($request->boolean('save_and_new')) {
            // Log aktivitas
            \App\Services\ActivityLogger::logCreated(
                store: $store,
                subject: $product,
                description: "Membuat produk: {$product->name}",
                metadata: ['sku' => $product->sku, 'price' => $product->price]
            );

            return redirect()->route('products.create')
                ->with('success', 'Produk berhasil ditambahkan! Silakan tambah produk berikutnya.');
        }

        // Log aktivitas
        \App\Services\ActivityLogger::logCreated(
            store: $store,
            subject: $product,
            description: "Membuat produk: {$product->name}",
            metadata: ['sku' => $product->sku, 'price' => $product->price]
        );

        return redirect()->route('products.index')
            ->with('success', 'Produk berhasil ditambahkan!');
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);

        $product->load('store');

        return Inertia::render('Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product)
    {
        $this->authorize('update', $product);

        $store = $product->store;

        // Ensure all required fields have default values if null
        $productData = [
            'id' => $product->id,
            'name' => $product->name ?? '',
            'sku' => $product->sku ?? '',
            'barcode' => $product->barcode ?? '',
            'description' => $product->description ?? '',
            'price' => $product->price ?? 0,
            'cost_price' => $product->cost_price ?? 0,
            'average_cost' => $product->average_cost ?? $product->cost_price ?? 0,
            'stock_quantity' => $product->stock_quantity ?? 0,
            'min_stock_level' => $product->min_stock_level ?? 0,
            'product_category_id' => $product->product_category_id,
            'category' => $product->category ?? '',
            'brand' => $product->brand ?? '',
            'unit' => $product->unit ?? 'pcs',
            'inventory_type' => InventoryType::of($product),
            'base_unit' => $product->baseUnit(),
            'tax_rate' => $product->tax_rate ?? 0,
            'track_stock' => $product->track_stock ?? false,
            'unlimited_stock' => $product->unlimited_stock ?? false,
            'allow_negative_stock' => $product->allow_negative_stock ?? false,
            'allows_decimal' => $product->allows_decimal ?? false,
            'has_variants' => $product->has_variants ?? false,
            'is_bundle' => $product->is_bundle ?? false,
            'is_active' => $product->is_active ?? true,
            'images' => $product->images ?? [],
            'image_url' => $product->getImageUrlAttribute(),
            'modifier_options' => $product->modifier_options ?? [],
            'pharmacy_sale_class' => $product->pharmacy_sale_class ?? 'otc',
            'pharmacy_registration_no' => $product->pharmacy_registration_no ?? '',
            'service_duration_minutes' => $product->service_duration_minutes,
            'barber_commission_mode' => $product->barber_commission_mode,
            'barber_commission_value' => $product->barber_commission_value !== null ? (float) $product->barber_commission_value : null,
        ];

        \Log::debug('[PRODUCT_EDIT_RENDER] Data yang dikirim ke halaman edit', [
            'id' => $product->id,
            'unlimited_stock' => $productData['unlimited_stock'],
        ]);

        $variants = $product->variants()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => $v->price,
                'cost_price' => $v->cost_price,
                'stock_quantity' => $v->stock_quantity,
                'min_stock_level' => $v->min_stock_level,
                'is_active' => $v->is_active,
            ])
            ->values()
            ->all();

        $bundleItemsPayload = [];
        if ($product->is_bundle) {
            $bundleItemsPayload = $product->bundleItems()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ProductBundleItem $b) => [
                    'component_product_id' => $b->component_product_id,
                    'component_product_variant_id' => $b->component_product_variant_id,
                    'quantity' => $b->quantity,
                ])
                ->values()
                ->all();
        }

        $bundleCatalogProducts = $this->bundleCatalogProductsForStore($store, $product);
        $saleConsumptionCatalog = $this->saleConsumptionCatalogForStore($store, $product);
        $saleConsumptionsPayload = $product->saleConsumptions()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSaleConsumption $row) => [
                'product_variant_id' => $row->product_variant_id,
                'component_product_id' => $row->component_product_id,
                'quantity' => $row->quantity,
                'base_unit' => $row->base_unit,
                'sort_order' => $row->sort_order,
            ])
            ->values()
            ->all();

        $productCategories = $this->productCategoriesQuery($store)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        $batches = [];
        if (BatchTracking::enabled($store) && ! $product->has_variants && ! $product->is_bundle) {
            $batches = $product->batches()
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->get()
                ->map(fn (ProductBatch $b) => [
                    'id' => $b->id,
                    'quantity_remaining' => $b->quantity_remaining,
                    'expiry_date' => $b->expiry_date?->format('Y-m-d') ?? '',
                    'batch_code' => $b->batch_code ?? '',
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Products/Edit', [
            'product' => $productData,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'wholesaleEnabled' => (bool) ($store->settings['wholesale_enabled'] ?? false),
            'productCategories' => $productCategories,
            'catalogPresets' => BusinessTypePresets::catalog($store->business_type ?? 'general'),
            'inventoryTypes' => InventoryType::LABELS,
            'baseUnits' => BaseUnit::LABELS,
            'batchTrackingEnabled' => BatchTracking::enabled($store),
            'fnbModifierEnabled' => ($store->business_type ?? '') === 'fnb',
            'pharmacyFieldsEnabled' => PharmacyFeatures::enabled($store),
            'barbershopEnabled' => BarbershopFeatures::enabled($store),
            'batches' => $batches,
            'variants' => $variants,
            'bundleItems' => $bundleItemsPayload,
            'bundleCatalogProducts' => $bundleCatalogProducts,
            'saleConsumptionCatalog' => $saleConsumptionCatalog,
            'saleConsumptions' => $saleConsumptionsPayload,
            // Toko GrowUp mengoreksi stok lewat dokumen penyesuaian, kecuali admin mengaktifkan direct_stock_edit.
            'stockLockedToAdjustment' => StorePlanLimits::allowsAdvancedRetailFeatures($store)
                && ! StockAdjustmentApproval::directStockEdit($store),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        // Debug: Log payload diterima
        \Log::debug('[PRODUCT_UPDATE_START] payload diterima:', [
            'unlimited_stock_raw' => $request->input('unlimited_stock'),
            'track_stock_raw' => $request->input('track_stock'),
            'all' => $request->except('images'),
        ]);

        // Debug: Log request data
        \Log::info('Update request data', ['data' => $request->all()]);
        \Log::info('Request method', ['method' => $request->method()]);
        \Log::info('Content type', ['content_type' => $request->header('Content-Type')]);
        \Log::info('Has files', ['has_files' => $request->hasFile('images')]);
        \Log::info('Files count', ['files_count' => $request->file('images') ? count($request->file('images')) : 0]);

        // Debug: Log specific required fields
        \Log::info('Required fields check', [
            'name' => $request->input('name'),
            'price' => $request->input('price'),
            'stock_quantity' => $request->input('stock_quantity'),
            'min_stock_level' => $request->input('min_stock_level'),
            'unit' => $request->input('unit'),
        ]);

        $request->merge([
            'allows_decimal' => $request->boolean('allows_decimal'),
            'has_variants' => $request->boolean('has_variants'),
            'is_bundle' => $request->boolean('is_bundle'),
            'unlimited_stock' => filter_var($request->input('unlimited_stock'), FILTER_VALIDATE_BOOLEAN),
            'allow_negative_stock' => filter_var($request->input('allow_negative_stock'), FILTER_VALIDATE_BOOLEAN),
            'track_stock' => filter_var($request->input('track_stock'), FILTER_VALIDATE_BOOLEAN),
            'product_category_id' => $request->filled('product_category_id')
                ? (int) $request->input('product_category_id')
                : null,
        ]);

        \Log::debug('[PRODUCT_UPDATE_AFTER_MERGE] Nilai setelah merge:', [
            'unlimited_stock' => $request->input('unlimited_stock'),
            'unlimited_stock_type' => gettype($request->input('unlimited_stock')),
            'track_stock' => $request->input('track_stock'),
        ]);

        $validated = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255|unique:products,sku,'.$product->id.',id,store_id,'.$product->store_id,
            'barcode' => 'nullable|string|max:255|unique:products,barcode,'.$product->id.',id,store_id,'.$product->store_id,
            'description' => 'nullable|string',
            'price' => [Rule::requiredIf(! $request->boolean('has_variants')), 'nullable', 'numeric', 'min:0'],
            'wholesale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
            'product_category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where('store_id', $product->store_id),
            ],
            'brand' => 'nullable|string|max:255',
            'unit' => 'required|string|max:50',
            'inventory_type' => 'nullable|string|in:'.implode(',', array_keys(InventoryType::LABELS)),
            'base_unit' => 'nullable|string|in:'.implode(',', array_keys(BaseUnit::LABELS)),
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'track_stock' => 'boolean',
            'unlimited_stock' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'allows_decimal' => 'boolean',
            'has_variants' => 'boolean',
            'is_bundle' => 'boolean',
            'is_active' => 'boolean',
            'batches_json' => 'nullable|string|max:65500',
            'variants_json' => 'nullable|string|max:65500',
            'bundles_json' => 'nullable|string|max:65500',
            'sale_consumptions_json' => 'nullable|string|max:65500',
            'modifier_options_json' => 'nullable|string|max:65535',
            'service_duration_minutes' => 'nullable|integer|min:1|max:1440',
            'barber_commission_mode' => 'sometimes|nullable|string|in:percent,fixed',
            'barber_commission_value' => 'sometimes|nullable|numeric|min:0|max:100000000',
        ], $this->pharmacyValidationRules($product->store)));

        \Log::debug('[PRODUCT_UPDATE_VALIDATED] Hasil validasi:', [
            'unlimited_stock' => $validated['unlimited_stock'] ?? 'TIDAK ADA',
            'track_stock' => $validated['track_stock'] ?? 'TIDAK ADA',
            'is_bundle' => $validated['is_bundle'] ?? 'TIDAK ADA',
        ]);

        $hasVariants = (bool) ($validated['has_variants'] ?? false);
        $isBundle = (bool) ($validated['is_bundle'] ?? false);
        $allowsDecimal = (bool) ($validated['allows_decimal'] ?? false);

        $requestedBaseUnit = BaseUnit::normalizeBaseUnit($validated['base_unit'] ?? $product->base_unit);
        if (
            $requestedBaseUnit !== $product->baseUnit()
            && ProductSaleConsumption::query()->where('component_product_id', $product->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'base_unit' => 'Satuan dasar tidak dapat diubah karena produk dipakai dalam konsumsi saat penjualan.',
            ]);
        }

        if (
            $requestedBaseUnit !== $product->baseUnit()
            && ProductQuantity::cmp((string) $product->stock_quantity, '0.0000') !== 0
        ) {
            throw ValidationException::withMessages([
                'base_unit' => 'Satuan dasar tidak dapat diubah selama produk masih memiliki stok. Kosongkan stok melalui penyesuaian terkontrol terlebih dahulu.',
            ]);
        }

        $this->lockProductRetailOptionsForPlan($product->store, $product, $allowsDecimal, $hasVariants, $isBundle);

        if ($isBundle && $hasVariants) {
            throw ValidationException::withMessages([
                'is_bundle' => 'Produk paket tidak bisa dikombinasikan dengan varian.',
            ]);
        }

        if ($hasVariants && BatchTracking::enabled($product->store)) {
            throw ValidationException::withMessages([
                'has_variants' => 'Produk dengan varian belum mendukung mode batch & ED.',
            ]);
        }

        if ($hasVariants) {
            $vRows = $this->decodeVariantsJson($request->input('variants_json'));
            if ($vRows === null || count($vRows) < 1) {
                throw ValidationException::withMessages([
                    'variants_json' => 'Lengkapi data varian (minimal satu).',
                ]);
            }
        }

        if ($isBundle) {
            $bRows = $this->decodeBundlesJson($request->input('bundles_json'));
            if ($bRows === null || count($bRows) < 1) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Lengkapi komponen paket (minimal satu).',
                ]);
            }
        }

        $oldStock = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);
        $oldVariantsSum = ProductQuantity::normalizeStorageAmount(
            $product,
            ProductVariant::where('product_id', $product->id)->sum('stock_quantity'),
        );

        $qtyProxy = new Product(['allows_decimal' => $allowsDecimal]);

        // Toko GrowUp mengoreksi stok lewat dokumen penyesuaian agar ada jejak audit;
        // paket Starter tetap memakai form ini karena tidak punya jalur pengganti.
        // Admin dapat mengaktifkan direct_stock_edit agar GrowUp juga bisa edit langsung.
        $stockLockedToAdjustment = StorePlanLimits::allowsAdvancedRetailFeatures($product->store)
            && ! StockAdjustmentApproval::directStockEdit($product->store);

        $categoryFields = $this->categoryFieldsForStore($product->store, $validated['product_category_id'] ?? null);

        $dataToUpdate = [
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'barcode' => $validated['barcode'],
            'description' => $validated['description'],
            'price' => isset($validated['price']) && is_numeric($validated['price']) ? (float) $validated['price'] : 0,
            'wholesale_price' => ($product->store->settings['wholesale_enabled'] ?? false)
                && isset($validated['wholesale_price']) && is_numeric($validated['wholesale_price']) && (float) $validated['wholesale_price'] > 0
                ? (float) $validated['wholesale_price']
                : null,
            'cost_price' => $validated['cost_price'] ? (float) $validated['cost_price'] : null,
            'average_cost' => $validated['cost_price'] ? (float) $validated['cost_price'] : null,
            'stock_quantity' => $hasVariants
                ? $oldStock
                : ($isBundle
                    ? '0.0000'
                    : ($stockLockedToAdjustment
                        ? $oldStock
                        : ProductQuantity::normalizeStorageAmount($qtyProxy, $validated['stock_quantity']))),
            'min_stock_level' => $hasVariants || $isBundle
                ? '0.0000'
                : ProductQuantity::normalizeStorageAmount($qtyProxy, $validated['min_stock_level']),
            'product_category_id' => $categoryFields['product_category_id'],
            'category' => $categoryFields['category'],
            'brand' => $validated['brand'],
            'unit' => $validated['unit'],
            'inventory_type' => InventoryType::normalize($validated['inventory_type'] ?? $product->inventory_type),
            'base_unit' => $requestedBaseUnit,
            'tax_rate' => $validated['tax_rate'] ? (float) $validated['tax_rate'] : 0,
            'track_stock' => $isBundle ? false : (bool) $request->input('track_stock'),
            'unlimited_stock' => $isBundle ? false : (bool) $request->input('unlimited_stock'),
            'allow_negative_stock' => $isBundle ? false : (bool) $request->input('allow_negative_stock'),
            'allows_decimal' => $allowsDecimal,
            'has_variants' => $hasVariants && ! $isBundle,
            'is_bundle' => $isBundle,
            'is_active' => (bool) $validated['is_active'],
        ];
        $dataToUpdate = array_merge($dataToUpdate, $this->pharmacyFieldsForPayload($product->store, $validated));

        if (BarbershopFeatures::enabled($product->store)) {
            $dataToUpdate['service_duration_minutes'] = $validated['service_duration_minutes'] ?? null;
            $dataToUpdate['barber_commission_mode'] = $validated['barber_commission_mode'] ?? null;
            $dataToUpdate['barber_commission_value'] = $validated['barber_commission_value'] ?? null;
        }

        if (($product->store->business_type ?? '') === 'fnb') {
            $dataToUpdate['modifier_options'] = $isBundle
                ? []
                : $this->decodeModifierOptionsJson($request->input('modifier_options_json'));
        }

        if ($hasVariants) {
            if (StorePlanLimits::allowsAdvancedRetailFeatures($product->store)) {
                ProductBundleItem::where('bundle_product_id', $product->id)->delete();
                ProductBatch::where('product_id', $product->id)->delete();
                $vDecode = $this->decodeVariantsJson($request->input('variants_json'));
                $this->replaceProductVariants($product, $product->store, $vDecode ?? [], $qtyProxy);
            }
            $product->refresh();
            $dataToUpdate['stock_quantity'] = (string) $product->stock_quantity;
        } elseif ($isBundle) {
            if (StorePlanLimits::allowsAdvancedRetailFeatures($product->store)) {
                ProductVariant::where('product_id', $product->id)->delete();
                ProductBatch::where('product_id', $product->id)->delete();
                $bDecode = $this->decodeBundlesJson($request->input('bundles_json'));
                $this->replaceProductBundleItems($product, $product->store, $bDecode ?? []);
                $dataToUpdate['stock_quantity'] = '0.0000';
                $dataToUpdate['min_stock_level'] = '0.0000';
            } else {
                $product->refresh();
                $dataToUpdate['stock_quantity'] = (string) $product->stock_quantity;
                $dataToUpdate['min_stock_level'] = (string) $product->min_stock_level;
            }
        } else {
            ProductBundleItem::where('bundle_product_id', $product->id)->delete();
            ProductVariant::where('product_id', $product->id)->delete();

            if (BatchTracking::enabled($product->store) && $dataToUpdate['track_stock']) {
                $rows = $this->decodeBatchesJson($request->input('batches_json'));
                if ($rows === null) {
                    throw ValidationException::withMessages([
                        'batches_json' => 'Susunan batch wajib diisi saat mode batch aktif.',
                    ]);
                }
                $this->assertPharmacyBatchExpiryRules($product->store, $rows);
                $this->replaceProductBatches($product, $rows);
                $sumB = ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
                $dataToUpdate['stock_quantity'] = ProductQuantity::normalizeStorageAmount($qtyProxy, $sumB);
            }

            if (BatchTracking::enabled($product->store) && ! $dataToUpdate['track_stock']) {
                ProductBatch::where('product_id', $product->id)->delete();
            }
        }

        if (! $hasVariants && ! $isBundle && BatchTracking::enabled($product->store) && ! $dataToUpdate['track_stock']) {
            ProductBatch::where('product_id', $product->id)->delete();
        }

        $saleConsumptionRows = $this->decodeSaleConsumptionsJson($request->input('sale_consumptions_json')) ?? [];
        $saleConsumptionTarget = $product->replicate();
        $saleConsumptionTarget->id = $product->id;
        $saleConsumptionTarget->inventory_type = $dataToUpdate['inventory_type'];
        $saleConsumptionTarget->is_bundle = $dataToUpdate['is_bundle'];
        $saleConsumptionTarget->has_variants = $dataToUpdate['has_variants'];
        $normalizedSaleConsumptions = $this->normalizeSaleConsumptions(
            $saleConsumptionTarget,
            $product->store,
            $saleConsumptionRows,
        );
        if ($this->hasProductionOutputConsumption($normalizedSaleConsumptions)) {
            $dataToUpdate['cost_price'] = null;
            $dataToUpdate['average_cost'] = null;
        }

        \Log::debug('[PRODUCT_UPDATE_DB] Mengeksekusi update pada database', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'dataToUpdate_unlimited_stock' => $dataToUpdate['unlimited_stock'],
            'dataToUpdate_unlimited_stock_type' => gettype($dataToUpdate['unlimited_stock']),
            'dataToUpdate_track_stock' => $dataToUpdate['track_stock'],
            'current_db_unlimited_stock' => $product->unlimited_stock,
            'is_bundle' => $isBundle,
            'is_dirty' => $product->isDirty('unlimited_stock'),
        ]);

        $updateSuccess = DB::transaction(function () use ($product, $dataToUpdate, $normalizedSaleConsumptions) {
            $updated = $product->update($dataToUpdate);
            $this->replaceSaleConsumptions($product, $normalizedSaleConsumptions);

            return $updated;
        });

        $freshProduct = $product->fresh();
        \Log::debug('[PRODUCT_UPDATE_DB_RESULT] Hasil update', [
            'success' => $updateSuccess,
            'fresh_unlimited_stock' => $freshProduct->unlimited_stock,
            'fresh_unlimited_stock_type' => gettype($freshProduct->unlimited_stock),
            'fresh_track_stock' => $freshProduct->track_stock,
            'product_changes' => $product->getChanges(),
        ]);

        // Sync stok produk jual yang bergantung pada produk hasil produksi ini
        if ($freshProduct->isProductionOutput()) {
            // Sisa batch produksi harus ikut digeser. Tanpa ini penjualan berikutnya
            // menimpa stock_quantity dengan total sisa batch, sehingga koreksi hilang.
            if ($freshProduct->track_stock && ! $freshProduct->unlimited_stock) {
                DB::transaction(function () use ($freshProduct) {
                    \App\Support\ProductionStockOperations::syncBatchesToStock(
                        $freshProduct,
                        (string) $freshProduct->stock_quantity,
                    );
                });
            }

            $dependentConsumptions = \App\Models\ProductSaleConsumption::where('component_product_id', $freshProduct->id)->get();

            // Batch-load related models to avoid N+1
            $variantIds = $dependentConsumptions->pluck('product_variant_id')->filter()->unique()->values()->all();
            $dependentVariants = \App\Models\ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');

            $parentProductIds = $dependentVariants->pluck('product_id')->unique()->values()->all();
            $parentProducts = \App\Models\Product::whereIn('id', $parentProductIds)->get()->keyBy('id');

            $productIds = $dependentConsumptions->whereNull('product_variant_id')->pluck('product_id')->filter()->unique()->values()->all();
            $dependentProducts = \App\Models\Product::whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($dependentConsumptions as $consumption) {
                // Cari produk induk (atau variannya)
                if ($consumption->product_variant_id) {
                    $dependentVariant = $dependentVariants->get($consumption->product_variant_id);
                    if ($dependentVariant) {
                        $maxPortions = bcdiv((string) $freshProduct->stock_quantity, (string) $consumption->quantity, \App\Support\ProductQuantity::SCALE);
                        $dependentVariant->update(['stock_quantity' => $maxPortions]);
                        // Jangan lupa update stok induk dari sum variannya
                        $parentProduct = $parentProducts->get($dependentVariant->product_id);
                        if ($parentProduct) {
                            $sumVariants = \App\Models\ProductVariant::where('product_id', $parentProduct->id)->sum('stock_quantity');
                            $parentProduct->update(['stock_quantity' => $sumVariants]);
                        }
                    }
                } else {
                    $dependentProduct = $dependentProducts->get($consumption->product_id);
                    if ($dependentProduct && $dependentProduct->isSellable() && $dependentProduct->track_stock && $consumption->quantity > 0) {
                        $maxPortions = bcdiv((string) $freshProduct->stock_quantity, (string) $consumption->quantity, \App\Support\ProductQuantity::SCALE);
                        $dependentProduct->update(['stock_quantity' => $maxPortions]);
                    }
                }
            }
        }

        $newStock = $dataToUpdate['stock_quantity'];

        if ($dataToUpdate['track_stock'] && $hasVariants) {
            $delta = bcsub($newStock, $oldVariantsSum, ProductQuantity::SCALE);
            if (bccomp($delta, '0.0000', ProductQuantity::SCALE) !== 0) {
                StockMovement::create([
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'quantity_change' => $delta,
                    'type' => StockMovement::TYPE_ADJUSTMENT,
                    'balance_after' => $newStock,
                    'notes' => 'Penyesuaian stok varian (edit produk)',
                ]);
            }
        } elseif ($dataToUpdate['track_stock'] && ! $hasVariants) {
            $delta = bcsub($newStock, $oldStock, ProductQuantity::SCALE);
            if (bccomp($delta, '0.0000', ProductQuantity::SCALE) !== 0) {
                StockMovement::create([
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'quantity_change' => $delta,
                    'type' => StockMovement::TYPE_ADJUSTMENT,
                    'balance_after' => $newStock,
                    'notes' => 'Penyesuaian dari halaman edit produk',
                ]);
            }
        }

        // Handle file uploads if present
        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                // Validate individual file
                if ($image->isValid() && in_array(strtolower($image->getClientOriginalExtension()), ['jpeg', 'png', 'jpg', 'gif', 'webp'])) {
                    $path = $image->store('products', 'public');
                    $imagePaths[] = $path;
                }
            }
            if (! empty($imagePaths)) {
                $product->update(['images' => $imagePaths]);
            }
        }

        // Log aktivitas
        \App\Services\ActivityLogger::logUpdated(
            store: $product->store_id,
            subject: $product,
            description: "Memperbarui produk: {$product->name}"
        );

        return redirect()->route('products.index')
            ->with('success', 'Produk berhasil diperbarui!');
    }

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        if ($product->images) {
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image);
            }
        }

        $product->delete();

        // Log aktivitas
        \App\Services\ActivityLogger::logDeleted(
            store: $product->store_id,
            subject: $product,
            description: "Menghapus produk: {$product->name}"
        );

        return redirect()->route('products.index')
            ->with('success', 'Produk berhasil dihapus!');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:products,id',
        ]);

        $store = auth()->user()->store;

        if (! $store) {
            return response()->json(['deleted' => 0, 'errors' => ['Tidak ada toko aktif.']], 422);
        }

        $deleted = 0;
        $errors = [];

        // Dibatasi ke toko yang sedang aktif: tanpa ini, id dari outlet lain yang
        // ikut terkirim akan menghapus produk di katalog outlet tersebut.
        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get()
            ->keyBy('id');

        foreach ($validated['ids'] as $id) {
            $product = $products->get($id);
            if (! $product) {
                $errors[] = "Produk ID {$id} tidak ditemukan di toko ini.";

                continue;
            }
            if (auth()->user()->cannot('delete', $product)) {
                $errors[] = "Produk «{$product->name}» tidak dapat dihapus dari toko ini.";

                continue;
            }
            if ($product->images) {
                foreach ($product->images as $image) {
                    Storage::disk('public')->delete($image);
                }
            }
            $product->delete();
            $deleted++;
        }

        // Log aktivitas bulk delete
        if ($deleted > 0) {
            \App\Services\ActivityLogger::log(
                store: auth()->user()->store_id,
                action: 'product.bulk_deleted',
                description: "Menghapus {$deleted} produk sekaligus",
                metadata: ['deleted_count' => $deleted, 'requested_count' => count($validated['ids'])]
            );
        }

        return response()->json([
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    /**
     * @return array{product_category_id: int|null, category: string|null}
     */
    private function categoryFieldsForStore(Store $store, ?int $productCategoryId): array
    {
        if ($productCategoryId === null) {
            return ['product_category_id' => null, 'category' => null];
        }

        $cat = ProductCategory::query()
            ->where('store_id', $store->id)
            ->whereKey($productCategoryId)
            ->first();

        if (! $cat) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Kategori tidak ditemukan.',
            ]);
        }

        return ['product_category_id' => $cat->id, 'category' => $cat->name];
    }

    /**
     * @return array<int, array{key: string, label: string, price: float}>|null
     */
    private function decodeModifierOptionsJson(?string $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'modifier_options_json' => 'Format opsi modifier tidak valid (JSON).',
            ]);
        }

        return ModifierOptions::normalize($decoded);
    }

    /**
     * @return array<int, array{quantity_remaining: mixed, expiry_date: ?string, batch_code: ?string}>|null
     */
    private function decodeBatchesJson(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'batches_json' => 'Format data batch tidak valid (JSON).',
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    /**
     * @return array<int, mixed>|null
     */
    private function decodeVariantsJson(?string $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'variants_json' => 'Format data varian tidak valid (JSON).',
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function replaceProductVariants(Product $product, Store $store, array $rows, Product $qtyContext): void
    {
        $this->assertVariantSkuBarcodeUniqueInStore($store, $product->id, $rows);

        $wholesaleEnabled = (bool) ($store->settings['wholesale_enabled'] ?? false);
        $normalized = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            if ($name === '') {
                continue;
            }

            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            $sku = $sku === '' ? null : mb_substr($sku, 0, 255);
            $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : '';
            $barcode = $barcode === '' ? null : mb_substr($barcode, 0, 255);

            $price = null;
            if (isset($row['price']) && $row['price'] !== '' && is_numeric($row['price'])) {
                $price = round((float) $row['price'], 2);
            }

            $cost = null;
            if (isset($row['cost_price']) && $row['cost_price'] !== '' && is_numeric($row['cost_price'])) {
                $cost = round((float) $row['cost_price'], 2);
            }

            $wholesalePrice = null;
            if ($wholesaleEnabled && isset($row['wholesale_price']) && $row['wholesale_price'] !== '' && is_numeric($row['wholesale_price']) && (float) $row['wholesale_price'] > 0) {
                $wholesalePrice = round((float) $row['wholesale_price'], 2);
            }

            $variantId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            if ($variantId !== null && ! ProductVariant::query()
                ->whereKey($variantId)
                ->where('product_id', $product->id)
                ->where('store_id', $store->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'variants_json' => 'Varian yang akan diperbarui tidak valid.',
                ]);
            }

            $normalized[] = [
                'id' => $variantId,
                'name' => mb_substr($name, 0, 120),
                'sku' => $sku,
                'barcode' => $barcode,
                'price' => $price,
                'cost_price' => $cost,
                'wholesale_price' => $wholesalePrice,
                'stock_quantity' => ProductQuantity::normalizeStorageAmount($qtyContext, $row['stock_quantity'] ?? 0),
                'min_stock_level' => ProductQuantity::normalizeStorageAmount($qtyContext, $row['min_stock_level'] ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $sort,
            ];
            $sort++;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'variants_json' => 'Minimal satu varian dengan nama terisi.',
            ]);
        }

        $keptVariantIds = [];
        foreach ($normalized as $r) {
            $variant = $r['id']
                ? ProductVariant::query()->whereKey($r['id'])->where('product_id', $product->id)->firstOrFail()
                : new ProductVariant([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                ]);
            $variant->fill([
                'name' => $r['name'],
                'sku' => $r['sku'],
                'barcode' => $r['barcode'],
                'price' => $r['price'],
                'cost_price' => $r['cost_price'],
                'wholesale_price' => $r['wholesale_price'],
                'stock_quantity' => $r['stock_quantity'],
                'min_stock_level' => $r['min_stock_level'],
                'is_active' => $r['is_active'],
                'sort_order' => $r['sort_order'],
            ]);
            $variant->save();
            $keptVariantIds[] = $variant->id;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $keptVariantIds)
            ->delete();

        $sum = (string) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
        $product->update([
            'stock_quantity' => ProductQuantity::normalizeStorageAmount($qtyContext, $sum),
        ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function assertVariantSkuBarcodeUniqueInStore(Store $store, ?int $ignoreProductId, array $rows): void
    {
        $skus = [];
        $barcodes = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            if ($sku !== '') {
                $k = mb_strtolower($sku);
                if (isset($skus[$k])) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'SKU varian «'.$sku.'» duplikat dalam daftar.',
                    ]);
                }
                $skus[$k] = true;
            }
            $bc = isset($row['barcode']) ? trim((string) $row['barcode']) : '';
            if ($bc !== '') {
                $bk = mb_strtolower($bc);
                if (isset($barcodes[$bk])) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'Barcode varian duplikat dalam daftar.',
                    ]);
                }
                $barcodes[$bk] = true;
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            if ($sku !== '') {
                $q = Product::query()->where('store_id', $store->id)->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)]);
                if ($ignoreProductId) {
                    $q->where('id', '!=', $ignoreProductId);
                }
                if ($q->exists()) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'SKU «'.$sku.'» sudah dipakai produk lain.',
                    ]);
                }

                $qv = ProductVariant::query()->where('store_id', $store->id)->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)]);
                if ($ignoreProductId) {
                    $qv->where('product_id', '!=', $ignoreProductId);
                }
                if ($qv->exists()) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'SKU «'.$sku.'» sudah dipakai varian lain.',
                    ]);
                }
            }

            $bc = isset($row['barcode']) ? trim((string) $row['barcode']) : '';
            if ($bc !== '') {
                $q = Product::query()->where('store_id', $store->id)->whereRaw('LOWER(barcode) = ?', [mb_strtolower($bc)]);
                if ($ignoreProductId) {
                    $q->where('id', '!=', $ignoreProductId);
                }
                if ($q->exists()) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'Barcode «'.$bc.'» sudah dipakai produk lain.',
                    ]);
                }

                $qv = ProductVariant::query()->where('store_id', $store->id)->whereRaw('LOWER(barcode) = ?', [mb_strtolower($bc)]);
                if ($ignoreProductId) {
                    $qv->where('product_id', '!=', $ignoreProductId);
                }
                if ($qv->exists()) {
                    throw ValidationException::withMessages([
                        'variants_json' => 'Barcode «'.$bc.'» sudah dipakai varian lain.',
                    ]);
                }
            }
        }
    }

    /**
     * Apotek: setiap batch harus punya tanggal kedaluwarsa yang valid (Fase E2).
     *
     * @param  array<int, mixed>  $rows
     */
    private function assertPharmacyBatchExpiryRules(Store $store, array $rows): void
    {
        if (! PharmacyFeatures::enabled($store)) {
            return;
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'batches_json' => 'Minimal satu batch wajib untuk produk apotek yang melacak stok.',
            ]);
        }

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $exp = $row['expiry_date'] ?? null;
            if ($exp === null || trim((string) $exp) === '') {
                throw ValidationException::withMessages([
                    'batches_json' => 'Apotek: tanggal kedaluwarsa (ED) wajib untuk setiap batch (periksa baris #'.($i + 1).').',
                ]);
            }
            try {
                Carbon::parse((string) $exp);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'batches_json' => 'Tanggal ED tidak valid pada baris batch #'.($i + 1).'.',
                ]);
            }
        }
    }

    private function replaceProductBatches(Product $product, array $rows): void
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $qty = ProductQuantity::normalizeStorageAmount($product, $row['quantity_remaining'] ?? $row['quantity'] ?? 0);
            $exp = $row['expiry_date'] ?? null;
            if ($exp === '') {
                $exp = null;
            }
            $code = isset($row['batch_code']) ? mb_substr((string) $row['batch_code'], 0, 120) : null;
            if ($code === '') {
                $code = null;
            }
            $normalized[] = [
                'quantity_remaining' => $qty,
                'expiry_date' => $exp,
                'batch_code' => $code,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'batches_json' => 'Minimal satu baris batch.',
            ]);
        }

        ProductBatch::where('product_id', $product->id)->delete();
        foreach ($normalized as $r) {
            ProductBatch::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'quantity_remaining' => $r['quantity_remaining'],
                'expiry_date' => $r['expiry_date'],
                'batch_code' => $r['batch_code'],
            ]);
        }
    }

    /**
     * Produk yang boleh dipakai sebagai komponen paket (bukan paket lain).
     *
     * @return list<array<string, mixed>>
     */
    private function bundleCatalogProductsForStore(Store $store, ?Product $excludeProduct): array
    {
        $q = $store->products()
            ->where('is_bundle', false)
            ->where('is_active', true);
        if ($excludeProduct) {
            $q->where('id', '!=', $excludeProduct->id);
        }

        return $q->with(['variants' => fn ($vq) => $vq->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get(['id', 'name', 'has_variants', 'allows_decimal', 'unit'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'has_variants' => (bool) $p->has_variants,
                'allows_decimal' => (bool) $p->allows_decimal,
                'unit' => $p->unit,
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function replaceProductBundleItems(Product $bundleProduct, Store $store, array $rows): void
    {
        $normalized = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cid = (int) ($row['component_product_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $vid = null;
            if (isset($row['component_product_variant_id']) && $row['component_product_variant_id'] !== '' && $row['component_product_variant_id'] !== null) {
                $vid = (int) $row['component_product_variant_id'];
            }

            $comp = Product::query()->where('store_id', $store->id)->where('id', $cid)->first();
            if (! $comp) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Komponen produk tidak ditemukan.',
                ]);
            }
            $qtyNorm = ProductQuantity::normalizeStorageAmount($comp, $row['quantity'] ?? 1);
            if (ProductQuantity::cmp($qtyNorm, '0.0000') <= 0) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Kuantitas komponen «'.$comp->name.'» harus lebih dari nol.',
                ]);
            }

            $this->assertBundleComponentPairValid($store, $bundleProduct, $comp, $vid);

            $normalized[] = [
                'component_product_id' => $cid,
                'component_product_variant_id' => $vid,
                'quantity' => $qtyNorm,
                'sort_order' => $sort,
            ];
            $sort++;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'bundles_json' => 'Minimal satu komponen paket dengan produk valid.',
            ]);
        }

        ProductBundleItem::where('bundle_product_id', $bundleProduct->id)->delete();

        foreach ($normalized as $r) {
            ProductBundleItem::create([
                'bundle_product_id' => $bundleProduct->id,
                'component_product_id' => $r['component_product_id'],
                'component_product_variant_id' => $r['component_product_variant_id'],
                'quantity' => $r['quantity'],
                'sort_order' => $r['sort_order'],
            ]);
        }
    }

    private function assertBundleComponentPairValid(Store $store, Product $bundleProduct, Product $component, ?int $variantId): void
    {
        if ($component->id === $bundleProduct->id) {
            throw ValidationException::withMessages([
                'bundles_json' => 'Komponen tidak boleh sama dengan produk paket itu sendiri.',
            ]);
        }
        if ($component->is_bundle) {
            throw ValidationException::withMessages([
                'bundles_json' => 'Komponen «'.$component->name.'» adalah paket — tidak bisa dipakai sebagai isi paket.',
            ]);
        }
        if ($component->has_variants) {
            if (! $variantId) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Pilih varian untuk komponen «'.$component->name.'».',
                ]);
            }
            $v = ProductVariant::query()
                ->where('id', $variantId)
                ->where('product_id', $component->id)
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->first();
            if (! $v) {
                throw ValidationException::withMessages([
                    'bundles_json' => 'Varian komponen «'.$component->name.'» tidak valid.',
                ]);
            }
        } elseif ($variantId) {
            throw ValidationException::withMessages([
                'bundles_json' => 'Komponen «'.$component->name.'» tidak memakai varian.',
            ]);
        }
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeBundlesJson(?string $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'bundles_json' => 'Format data paket tidak valid (JSON).',
            ]);
        }

        return $decoded;
    }

    /**
     * Produk aktif yang boleh dikonsumsi ketika produk jual terjual.
     *
     * @return list<array<string, mixed>>
     */
    private function saleConsumptionCatalogForStore(Store $store, ?Product $excludeProduct): array
    {
        $query = $store->products()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->whereIn('inventory_type', [InventoryType::RAW_MATERIAL, InventoryType::PRODUCTION_OUTPUT, InventoryType::PACKAGING]);

        if ($excludeProduct) {
            $query->whereKeyNot($excludeProduct->id);
        }

        return $query->orderBy('name')
            ->get(['id', 'name', 'sku', 'inventory_type', 'base_unit', 'average_cost', 'cost_price', 'stock_quantity'])
            ->map(fn (Product $component) => [
                'id' => $component->id,
                'name' => $component->name,
                'sku' => $component->sku,
                'inventory_type' => $component->inventoryType(),
                'base_unit' => $component->baseUnit(),
                'average_cost' => $component->currentAverageCost(),
                'stock_quantity' => $component->stock_quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeSaleConsumptionsJson(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'sale_consumptions_json' => 'Format konsumsi saat penjualan tidak valid (JSON).',
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeSaleConsumptions(Product $target, Store $store, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        if ($target->inventoryType() !== InventoryType::SELLABLE || $target->is_bundle) {
            throw ValidationException::withMessages([
                'sale_consumptions_json' => 'Konsumsi saat penjualan hanya dapat diatur untuk produk jual non-paket.',
            ]);
        }

        $normalized = [];
        $duplicates = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Baris konsumsi #'.($index + 1).' tidak valid.',
                ]);
            }

            $componentId = (int) ($row['component_product_id'] ?? 0);
            $component = Product::query()
                ->where('store_id', $store->id)
                ->whereKey($componentId)
                ->where('is_active', true)
                ->where('track_stock', true)
                ->whereIn('inventory_type', [InventoryType::RAW_MATERIAL, InventoryType::PRODUCTION_OUTPUT, InventoryType::PACKAGING])
                ->first();

            if (! $component || ($target->id && (int) $component->id === (int) $target->id)) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Komponen pada baris #'.($index + 1).' tidak valid untuk toko ini.',
                ]);
            }

            $variantId = isset($row['product_variant_id']) && $row['product_variant_id'] !== ''
                ? (int) $row['product_variant_id']
                : null;

            if ($target->has_variants) {
                // null = berlaku untuk semua varian, angka = varian spesifik
                if ($variantId !== null) {
                    $variant = ProductVariant::query()
                        ->whereKey($variantId)
                        ->where('product_id', $target->id)
                        ->where('store_id', $store->id)
                        ->where('is_active', true)
                        ->first();
                    if (! $variant) {
                        throw ValidationException::withMessages([
                            'sale_consumptions_json' => 'Pilih varian aktif yang valid pada baris konsumsi #'.($index + 1).'.',
                        ]);
                    }
                }
            } elseif ($variantId !== null) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Produk tanpa varian tidak boleh memiliki target varian.',
                ]);
            }

            $quantity = $row['quantity'] ?? null;
            if (! is_numeric($quantity) || bccomp((string) $quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Kuantitas konsumsi pada baris #'.($index + 1).' harus lebih dari nol.',
                ]);
            }

            $inputUnit = trim((string) ($row['input_unit'] ?? $row['base_unit'] ?? ''));
            if (! BaseUnit::isConvertible($inputUnit, $component->baseUnit())) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Satuan konsumsi «'.$inputUnit.'» tidak sesuai dengan komponen «'.$component->name.'».',
                ]);
            }

            $duplicateKey = ($variantId ?? 'product').':'.$component->id;
            if (isset($duplicates[$duplicateKey])) {
                throw ValidationException::withMessages([
                    'sale_consumptions_json' => 'Komponen «'.$component->name.'» duplikat untuk target produk atau varian yang sama.',
                ]);
            }
            $duplicates[$duplicateKey] = true;

            $normalized[] = [
                'product_variant_id' => $variantId,
                'component_product_id' => $component->id,
                'quantity' => BaseUnit::toBaseQuantity($quantity, $inputUnit, $component->baseUnit()),
                'base_unit' => $component->baseUnit(),
                'sort_order' => count($normalized),
                'component_inventory_type' => $component->inventoryType(),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceSaleConsumptions(Product $product, array $rows): void
    {
        $product->saleConsumptions()->delete();

        foreach ($rows as $row) {
            ProductSaleConsumption::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'product_variant_id' => $row['product_variant_id'],
                'component_product_id' => $row['component_product_id'],
                'quantity' => $row['quantity'],
                'base_unit' => $row['base_unit'],
                'sort_order' => $row['sort_order'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function hasProductionOutputConsumption(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['component_inventory_type'] ?? null) === InventoryType::PRODUCTION_OUTPUT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function pharmacyValidationRules(Store $store): array
    {
        if (! PharmacyFeatures::enabled($store)) {
            return [];
        }

        return [
            'pharmacy_sale_class' => 'required|string|in:otc,limited,prescription',
            'pharmacy_registration_no' => 'nullable|string|max:128',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{pharmacy_sale_class: ?string, pharmacy_registration_no: ?string}
     */
    private function pharmacyFieldsForPayload(Store $store, array $validated): array
    {
        if (! PharmacyFeatures::enabled($store)) {
            return [
                'pharmacy_sale_class' => null,
                'pharmacy_registration_no' => null,
            ];
        }

        $reg = isset($validated['pharmacy_registration_no']) ? trim((string) $validated['pharmacy_registration_no']) : '';

        return [
            'pharmacy_sale_class' => (string) $validated['pharmacy_sale_class'],
            'pharmacy_registration_no' => $reg !== '' ? $reg : null,
        ];
    }

    /**
     * Kategori produk untuk toko — query langsung ke model (tetap jalan jika VPS belum deploy relasi Store::productCategories()).
     *
     * @return Builder<ProductCategory>
     */
    private function productCategoriesQuery(Store $store): Builder
    {
        return ProductCategory::query()->where('store_id', $store->id);
    }

    /** Izin kategori: sama dengan ProductCategoryPolicy (inline agar tidak bergantung file Policy di VPS). */
    private function authorizeInventoryProductCategoriesAccess(): void
    {
        $user = auth()->user();
        abort_unless($user && $user->store_id !== null, 403, 'Akses ditolak.');
    }

    private function authorizeProductCategoryForUserStore(ProductCategory $productCategory): void
    {
        $user = auth()->user();
        abort_unless(
            $user
                && $user->store_id !== null
                && (int) $user->store_id === (int) $productCategory->store_id,
            403,
            'Akses ditolak.',
        );
    }

    /**
     * Paket Starter: produk baru tanpa desimal/varian/bundel. Saat edit, pertahankan nilai di DB.
     */
    private function lockProductRetailOptionsForPlan(Store $store, ?Product $existing, bool &$allowsDecimal, bool &$hasVariants, bool &$isBundle): void
    {
        if (StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return;
        }
        if ($existing === null) {
            $allowsDecimal = false;
            $hasVariants = false;
            $isBundle = false;

            return;
        }
        $allowsDecimal = (bool) $existing->allows_decimal;
        $hasVariants = (bool) $existing->has_variants;
        $isBundle = (bool) $existing->is_bundle;
    }

    /** Kategori produk — di ProductController agar satu file ikut deploy VPS (hindari class terpisah hilang). */
    public function productCategoriesIndex()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->authorizeInventoryProductCategoriesAccess();

        $categories = $this->productCategoriesQuery($store)
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('ProductCategories/Index', [
            'categories' => $categories,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
        ]);
    }

    public function productCategoriesStore(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->authorizeInventoryProductCategoriesAccess();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);

        ProductCategory::query()->create([
            'store_id' => $store->id,
            'name' => trim($validated['name']),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('product-categories.index')
            ->with('success', 'Kategori berhasil ditambahkan.');
    }

    /** Buat kategori via popup halaman tambah produk — balas JSON agar tetap di halaman. */
    public function productCategoriesQuickStore(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['message' => 'Anda perlu membuat toko terlebih dahulu.'], 400);
        }

        $this->authorizeInventoryProductCategoriesAccess();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($validated['name']);

        // Cegah duplikat (unique store_id+name) — jika sudah ada, kembalikan kategori itu.
        $existing = ProductCategory::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'name' => $existing->name,
            ]);
        }

        $category = ProductCategory::query()->create([
            'store_id' => $store->id,
            'name' => $name,
            'sort_order' => 0,
        ]);

        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
        ]);
    }

    public function productCategoriesUpdate(Request $request, ProductCategory $productCategory)
    {
        $this->authorizeProductCategoryForUserStore($productCategory);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);

        $oldName = $productCategory->name;
        $productCategory->update([
            'name' => trim($validated['name']),
            'sort_order' => (int) ($validated['sort_order'] ?? $productCategory->sort_order),
        ]);

        if ($oldName !== $productCategory->name) {
            Product::query()
                ->where('product_category_id', $productCategory->id)
                ->update(['category' => $productCategory->name]);
        }

        return redirect()->route('product-categories.index')
            ->with('success', 'Kategori diperbarui.');
    }

    public function productCategoriesDestroy(ProductCategory $productCategory)
    {
        $this->authorizeProductCategoryForUserStore($productCategory);

        Product::query()
            ->where('product_category_id', $productCategory->id)
            ->update([
                'product_category_id' => null,
                'category' => null,
            ]);

        $productCategory->delete();

        return redirect()->route('product-categories.index')
            ->with('success', 'Kategori dihapus.');
    }
}
