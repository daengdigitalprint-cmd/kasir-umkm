<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\Accounting\JournalPoster;
use App\Support\AdjustmentStockOperations;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StockAdjustmentApproval;
use App\Support\StorePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock opname: hitung fisik berkala dengan sesi berdraft.
 *
 * Berbeda dari penyesuaian insidental, sesi opname dibuka lebih dulu agar
 * lembar hitung dapat diisi bertahap, lalu diposting setelah selesai.
 */
class StockOpnameController extends Controller
{
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
            'canApprove' => $isPending && $canReview && ! $blockedBySegregation,
            'segregationBlocked' => $isPending && $canReview && $blockedBySegregation,
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
}
