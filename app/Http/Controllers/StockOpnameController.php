<?php

namespace App\Http\Controllers;

use App\Exports\StockOpnameTemplateExport;
use App\Jobs\ProcessStockOpnameImport;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockOpnameImport;
use App\Services\StockOpnameImportService;
use App\Support\Accounting\JournalPoster;
use App\Support\AdjustmentStockOperations;
use App\Support\BatchTracking;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StockAdjustmentApproval;
use App\Support\StorePlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock opname: hitung fisik berkala dengan sesi berdraft.
 *
 * Berbeda dari penyesuaian insidental, sesi opname dibuka lebih dulu agar
 * lembar hitung dapat diisi bertahap, lalu diposting setelah selesai.
 */
class StockOpnameController extends Controller
{
    /** Batas baris preview yang dikirim ke browser; data lengkap tetap dipakai saat apply. */
    private const IMPORT_PREVIEW_LIMIT = 500;

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $query = StockAdjustment::query()
            ->where('store_id', $store->id)
            ->where('type', StockAdjustment::TYPE_OPNAME)
            ->with(['createdBy:id,name', 'postedBy:id,name'])
            ->withCount('lines');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return Inertia::render('StockOpnames/Index', [
            'opnames' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => ['status' => $request->input('status')],
            'statusLabels' => [
                StockAdjustment::STATUS_DRAFT => 'Draft',
                StockAdjustment::STATUS_POSTED => 'Diposting',
                StockAdjustment::STATUS_CANCELLED => 'Dibatalkan',
            ],
        ]);
    }

    /**
     * Form pembukaan sesi: pilih cakupan produk yang akan dihitung.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        // Admin mengaktifkan direct_stock_edit: koreksi stok dilakukan lewat form edit produk.
        if (StockAdjustmentApproval::directStockEdit($store)) {
            return redirect()->route('products.index')
                ->with('info', 'Pengaturan «Edit stok langsung» aktif. Silakan koreksi stok melalui form Edit Produk.');
        }

        $categories = ProductCategory::query()
            ->where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('StockOpnames/Create', [
            'categories' => $categories,
            'countableTotal' => $this->countableProductsQuery($store)->count(),
        ]);
    }

    /**
     * Buka sesi opname dan bekukan stok sistem sebagai snapshot per baris.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'blind_count' => ['nullable', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', Rule::exists('product_categories', 'id')->where('store_id', $store->id)],
        ]);

        $products = $this->countableProductsQuery($store)
            ->when(
                ! empty($validated['category_ids']),
                fn ($q) => $q->whereIn('product_category_id', $validated['category_ids'])
            )
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'category_ids' => 'Tidak ada produk yang dapat dihitung pada cakupan tersebut.',
            ]);
        }

        $document = DB::transaction(function () use ($user, $store, $validated, $products) {
            $document = StockAdjustment::create([
                'store_id' => $store->id,
                'type' => StockAdjustment::TYPE_OPNAME,
                'reason_code' => StockAdjustment::REASON_OPNAME,
                'status' => StockAdjustment::STATUS_DRAFT,
                'notes' => $validated['notes'] ?? null,
                'counted_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($products as $product) {
                // Produk bervarian dihitung per varian, karena stok tersimpan di sana.
                if ($product->has_variants) {
                    foreach ($product->variants as $variant) {
                        $systemQty = AdjustmentStockOperations::currentQuantity($product, $variant);

                        StockAdjustmentLine::create([
                            'stock_adjustment_id' => $document->id,
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'system_quantity' => $systemQty,
                            'counted_quantity' => $systemQty,
                            'variance_quantity' => '0.0000',
                            'unit_cost' => InventoryCosting::currentAverageCost($product),
                            'variance_value' => '0.00',
                        ]);
                    }

                    continue;
                }

                // Stok sistem dibekukan di sini; penjualan setelah ini tidak menggeser selisih.
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

            return $document;
        });

        $lineCount = $document->lines()->count();

        return redirect()->route('stock-opnames.show', $document)
            ->with('success', "Sesi opname {$document->adjustment_number} dibuka dengan {$lineCount} baris hitung.");
    }

    public function show(Request $request, StockAdjustment $stockOpname): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);

        $stockOpname->load([
            'lines.product:id,name,sku,unit,allows_decimal,stock_quantity',
            'lines.productVariant:id,product_id,name,sku',
            'createdBy:id,name',
            'postedBy:id,name',
            'cancelledBy:id,name',
        ]);

        $canReview = $user->isAdmin() || $user->isManager();
        $isOwnDocument = (int) $stockOpname->created_by === (int) $user->id;
        $blockedBySegregation = StockAdjustmentApproval::segregationOfDuties($store) && $isOwnDocument;
        $isPending = $stockOpname->status === StockAdjustment::STATUS_PENDING_APPROVAL;

        return Inertia::render('StockOpnames/Show', [
            'opname' => $stockOpname,
            'statusLabel' => StockAdjustment::statusLabel((string) $stockOpname->status),
            'canPost' => $stockOpname->isDraft(),
            'canCancel' => ! $stockOpname->isCancelled() && $canReview,
            'canDelete' => $stockOpname->isCancelled() && $canReview,
            'canApprove' => $isPending && $canReview && ! $blockedBySegregation,
            'segregationBlocked' => $isPending && $canReview && $blockedBySegregation,
            'imports' => $stockOpname->imports()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (StockOpnameImport $import): array => $this->importPayload($import))
                ->all(),
        ]);
    }

    /**
     * Simpan hasil hitung tanpa memposting, agar sesi dapat dilanjutkan.
     */
    public function saveDraft(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        if (! $stockOpname->isDraft()) {
            throw ValidationException::withMessages([
                'lines' => 'Sesi ini sudah final dan tidak dapat diubah.',
            ]);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($stockOpname, $validated) {
            $lines = $stockOpname->lines()->with('product')->get()->keyBy('id');

            foreach ($validated['lines'] as $row) {
                $line = $lines->get((int) $row['id']);
                if (! $line || ! $line->product) {
                    continue;
                }

                $counted = ProductQuantity::normalizeStorageAmount($line->product, $row['counted_quantity']);
                $variance = AdjustmentStockOperations::variance($counted, (string) $line->system_quantity);

                $line->update([
                    'counted_quantity' => $counted,
                    'variance_quantity' => $variance,
                    'variance_value' => InventoryCosting::extendedCost($variance, (string) $line->unit_cost),
                    'line_notes' => $row['line_notes'] ?? $line->line_notes,
                ]);
            }

            $stockOpname->update([
                'total_variance_value' => (string) $stockOpname->lines()->sum('variance_value'),
            ]);
        });

        return redirect()->route('stock-opnames.show', $stockOpname)
            ->with('success', 'Hasil hitung tersimpan. Sesi dapat dilanjutkan kapan saja.');
    }

    /**
     * Unduh lembar hitung sesi: daftar produk beserta snapshot stok sistem.
     */
    public function template(Request $request, StockAdjustment $stockOpname): RedirectResponse|BinaryFileResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        $filename = 'lembar-hitung-'.Str::slug($stockOpname->adjustment_number ?: 'opname').'.xlsx';

        try {
            return Excel::download(
                new StockOpnameTemplateExport($stockOpname->id, BatchTracking::enabled($store)),
                $filename,
                \Maatwebsite\Excel\Excel::XLSX,
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ],
            );
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('stock-opnames.show', $stockOpname)
                ->with('error', 'Gagal membuat lembar hitung. Coba lagi beberapa saat lagi.');
        }
    }

    /**
     * Unggah berkas hasil hitung untuk diperiksa di latar belakang.
     *
     * Belum ada data yang ditulis ke lembar hitung; user meninjau preview
     * selisih lebih dulu lalu mengonfirmasi lewat importApply.
     */
    public function importStore(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        if (! $stockOpname->isDraft()) {
            throw ValidationException::withMessages([
                'file' => 'Sesi ini sudah final dan tidak dapat diubah.',
            ]);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
            'missing_mode' => ['nullable', Rule::in([StockOpnameImport::MODE_IGNORE, StockOpnameImport::MODE_ZERO])],
        ], [
            'file.required' => 'Pilih berkas hasil hitung terlebih dahulu.',
            'file.mimes' => 'Format berkas harus .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
            'missing_mode.in' => 'Pilihan perlakuan produk yang tidak ada di berkas tidak dikenal.',
        ]);

        try {
            $path = $request->file('file')->store('stock-opname-imports', 'local');
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'file' => 'Berkas gagal disimpan. Coba unggah ulang.',
            ]);
        }

        $import = StockOpnameImport::create([
            'store_id' => $store->id,
            'stock_adjustment_id' => $stockOpname->id,
            'created_by' => $user->id,
            'file_path' => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'missing_mode' => $validated['missing_mode'] ?? StockOpnameImport::MODE_IGNORE,
            'status' => StockOpnameImport::STATUS_PENDING,
        ]);

        ProcessStockOpnameImport::dispatch($import->id);

        return redirect()->route('stock-opnames.show', ['stock_opname' => $stockOpname->id, 'import' => $import->id])
            ->with('success', 'Berkas diterima dan sedang diperiksa. Tinjau preview selisih sebelum menerapkannya ke lembar hitung.');
    }

    /**
     * Status pemrosesan berkas beserta preview hasil untuk polling dari halaman sesi.
     */
    public function importShow(Request $request, StockAdjustment $stockOpname, StockOpnameImport $import): JsonResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            abort(403, 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        $this->assertOwnedImport($stockOpname, $import);

        $report = $import->report ?? [];
        $preview = $report['preview'] ?? [];

        return response()->json(array_merge($this->importPayload($import), [
            'errors' => $report['errors'] ?? [],
            'preview' => array_slice($preview, 0, self::IMPORT_PREVIEW_LIMIT),
            'preview_total' => count($preview),
        ]));
    }

    /**
     * Terapkan hasil berkas yang sudah dikonfirmasi ke lembar hitung sesi.
     */
    public function importApply(
        Request $request,
        StockAdjustment $stockOpname,
        StockOpnameImport $import,
        StockOpnameImportService $service,
    ): RedirectResponse {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        $this->assertOwnedImport($stockOpname, $import);
        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        try {
            $service->apply($import);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'import' => $e->getMessage(),
            ]);
        }

        return redirect()->route('stock-opnames.show', $stockOpname)
            ->with('success', 'Hasil hitung dari berkas diterapkan ke lembar hitung. Periksa kembali sebelum memposting.');
    }

    /**
     * Posting: terapkan selisih tiap baris ke stok berjalan.
     */
    public function post(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);

        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manajer yang dapat memposting stock opname.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        try {
            $document = DB::transaction(function () use ($user, $store, $stockOpname) {
                $document = StockAdjustment::query()
                    ->whereKey($stockOpname->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $document->assertCanTransitionTo(StockAdjustment::STATUS_POSTED);

                // Nilai selisih sudah dihitung saat draft; dipakai menentukan
                // apakah dokumen perlu ditahan untuk persetujuan.
                $document->update([
                    'total_variance_value' => (string) $document->lines()->sum('variance_value'),
                ]);

                if (StockAdjustmentApproval::requiresApproval($store, $document->fresh())) {
                    $document->update(['status' => StockAdjustment::STATUS_PENDING_APPROVAL]);

                    return $document->fresh();
                }

                $total = $this->applyAllLines($store, $user, $document);

                $document->update([
                    'status' => StockAdjustment::STATUS_POSTED,
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                    'total_variance_value' => $total,
                ]);

                return $document->fresh();
            });

            JournalPoster::postStockAdjustment($store, $document);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'lines' => $e->getMessage(),
            ]);
        }

        $message = $document->status === StockAdjustment::STATUS_PENDING_APPROVAL
            ? "Stock opname {$stockOpname->adjustment_number} menunggu persetujuan sebelum stok berubah."
            : "Stock opname {$stockOpname->adjustment_number} berhasil diposting.";

        return redirect()->route('stock-opnames.show', $stockOpname)->with('success', $message);
    }

    /**
     * Setujui sesi opname yang menunggu persetujuan, lalu terapkan selisihnya.
     */
    public function approve(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);
        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        try {
            DB::transaction(function () use ($user, $store, $stockOpname) {
                $document = StockAdjustment::query()
                    ->whereKey($stockOpname->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                StockAdjustmentApproval::assertCanApprove($store, $document, $user);
                $document->assertCanTransitionTo(StockAdjustment::STATUS_POSTED);

                $total = $this->applyAllLines($store, $user, $document);

                $document->update([
                    'status' => StockAdjustment::STATUS_POSTED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                    'total_variance_value' => $total,
                ]);
            });

            JournalPoster::postStockAdjustment($store, $stockOpname->fresh());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'approval' => $e->getMessage(),
            ]);
        }

        return redirect()->route('stock-opnames.show', $stockOpname)
            ->with('success', "Stock opname {$stockOpname->adjustment_number} disetujui dan diposting.");
    }

    /**
     * Terapkan seluruh baris dokumen ke stok; mengembalikan total nilai selisih.
     *
     * Pemanggil bertanggung jawab membuka transaksi dan mengunci dokumen.
     */
    private function applyAllLines($store, $user, StockAdjustment $document): string
    {
        $lines = $document->lines()->get();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $lines->pluck('product_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variants = ProductVariant::query()
            ->whereIn('id', $lines->pluck('product_variant_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $total = '0.00';

        foreach ($lines as $line) {
            $product = $products->get((int) $line->product_id);
            if (! $product) {
                continue;
            }

            $variant = $line->product_variant_id ? $variants->get((int) $line->product_variant_id) : null;

            AdjustmentStockOperations::assertAdjustable($store, $product, $variant);
            AdjustmentStockOperations::applyLine($store, $user, $product, $document, $line, $variant);

            $total = bcadd($total, (string) $line->fresh()->variance_value, 2);
        }

        return $total;
    }

    public function cancel(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);

        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manajer yang dapat membatalkan stock opname.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($user, $store, $stockOpname, $validated) {
                $document = StockAdjustment::query()
                    ->whereKey($stockOpname->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($document->isCancelled()) {
                    throw new \RuntimeException('Sesi ini sudah dibatalkan.');
                }

                // Sesi draft belum memutasi stok, cukup ubah status.
                if ($document->isPosted()) {
                    $lines = $document->lines()->get();
                    $productIds = $lines->pluck('product_id')->unique()->sort()->values();

                    $products = Product::query()
                        ->whereIn('id', $productIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $variants = ProductVariant::query()
                        ->whereIn('id', $lines->pluck('product_variant_id')->filter()->unique()->sort()->values())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($lines as $line) {
                        $product = $products->get((int) $line->product_id);
                        if (! $product) {
                            continue;
                        }
                        $variant = $line->product_variant_id ? $variants->get((int) $line->product_variant_id) : null;
                        AdjustmentStockOperations::reverseLine($store, $user, $product, $document, $line, $variant);
                    }
                }

                $document->update([
                    'status' => StockAdjustment::STATUS_CANCELLED,
                    'cancelled_by' => $user->id,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $validated['cancellation_reason'],
                ]);
            });
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'cancellation_reason' => $e->getMessage(),
            ]);
        }

        JournalPoster::reverseStockAdjustment($store, $stockOpname);

        return redirect()->route('stock-opnames.show', $stockOpname)
            ->with('success', "Stock opname {$stockOpname->adjustment_number} dibatalkan.");
    }

    /**
     * Hapus permanen sesi yang sudah dibatalkan.
     *
     * Sesi yang dibatalkan tidak lagi memengaruhi stok, sehingga aman dihapus
     * dari daftar. Berkas unggahan ikut dibersihkan dari penyimpanan lokal.
     */
    public function destroy(Request $request, StockAdjustment $stockOpname): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $this->assertOwnedOpname($store, $stockOpname);

        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manajer yang dapat menghapus stock opname.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur stock opname tersedia mulai paket GrowUp.');

        try {
            DB::transaction(function () use ($stockOpname) {
                $document = StockAdjustment::query()
                    ->whereKey($stockOpname->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $document->isCancelled()) {
                    throw new \RuntimeException('Hanya sesi yang sudah dibatalkan yang dapat dihapus permanen.');
                }

                foreach ($document->imports()->pluck('file_path') as $path) {
                    if (filled($path)) {
                        Storage::disk('local')->delete($path);
                    }
                }

                $document->delete();
            });
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'delete' => $e->getMessage(),
            ]);
        }

        return redirect()->route('stock-opnames.index')
            ->with('success', "Stock opname {$stockOpname->adjustment_number} berhasil dihapus permanen.");
    }

    /**
     * Produk yang layak masuk lembar hitung (lihat aturan 3.4).
     */
    private function countableProductsQuery($store)
    {
        return $store->products()
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->where('is_bundle', false)
            ->where('is_active', true)
            ->with(['variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
    }

    private function assertOwnedOpname($store, StockAdjustment $document): void
    {
        if ((int) $document->store_id !== (int) $store->id) {
            abort(403, 'Dokumen ini bukan milik toko Anda.');
        }
        if ($document->type !== StockAdjustment::TYPE_OPNAME) {
            abort(404);
        }
    }

    private function assertOwnedImport(StockAdjustment $document, StockOpnameImport $import): void
    {
        if ((int) $import->stock_adjustment_id !== (int) $document->id) {
            abort(404);
        }
    }

    /**
     * Ringkasan satu proses unggah berkas untuk ditampilkan di halaman sesi.
     *
     * @return array<string, mixed>
     */
    private function importPayload(StockOpnameImport $import): array
    {
        $report = $import->report ?? [];

        return [
            'id' => $import->id,
            'file_name' => $import->file_name,
            'status' => $import->status,
            'status_label' => StockOpnameImport::statusLabel((string) $import->status),
            'missing_mode' => $import->missing_mode,
            'missing_mode_label' => StockOpnameImport::missingModeLabel((string) $import->missing_mode),
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'matched_rows' => $import->matched_rows,
            'error_rows' => $import->error_rows,
            'error_message' => $import->error_message,
            'applied_at' => $import->applied_at?->toIso8601String(),
            'summary' => $report['summary'] ?? null,
            'can_apply' => $import->isCompleted()
                && ! $import->isApplied()
                && ($report['errors'] ?? []) === []
                && ($report['preview'] ?? []) !== [],
        ];
    }
}
