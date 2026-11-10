<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\Accounting\JournalPoster;
use App\Support\AdjustmentStockOperations;
use App\Support\BatchTracking;
use App\Support\InventoryCosting;
use App\Support\PharmacyFeatures;
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
 * Penyesuaian stok: dokumen koreksi bernomor dan beralasan.
 *
 * Tahap 2 membatasi cakupan pada produk biasa. Varian dan batch menyusul di Tahap 5.
 */
class StockAdjustmentController extends Controller
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
            ->where('type', StockAdjustment::TYPE_ADJUSTMENT)
            ->with(['createdBy:id,name', 'postedBy:id,name'])
            ->withCount('lines');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('reason_code')) {
            $query->where('reason_code', $request->input('reason_code'));
        }

        return Inertia::render('StockAdjustments/Index', [
            'adjustments' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => [
                'status' => $request->input('status'),
                'reason_code' => $request->input('reason_code'),
            ],
            'reasonLabels' => StockAdjustment::reasonLabels(),
            'statusLabels' => [
                StockAdjustment::STATUS_DRAFT => 'Draft',
                StockAdjustment::STATUS_PENDING_APPROVAL => 'Menunggu persetujuan',
                StockAdjustment::STATUS_POSTED => 'Diposting',
                StockAdjustment::STATUS_CANCELLED => 'Dibatalkan',
            ],
        ]);
    }

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

        $products = $store->products()
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->where('is_bundle', false)
            ->where('is_active', true)
            ->with(['variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get([
                'id', 'name', 'sku', 'unit', 'stock_quantity', 'average_cost',
                'cost_price', 'allows_decimal', 'has_variants',
            ]);

        return Inertia::render('StockAdjustments/Create', [
            'products' => $products,
            'batchTrackingEnabled' => BatchTracking::enabled($store),
            'pharmacyFieldsEnabled' => PharmacyFeatures::enabled($store),
            'reasonLabels' => collect(StockAdjustment::reasonLabels())
                ->except(StockAdjustment::REASON_OPNAME)
                ->all(),
        ]);
    }

    /**
     * Buat dokumen sekaligus posting. Penyesuaian bersifat insidental sehingga
     * tidak perlu tahap draft seperti opname.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur penyesuaian stok tersedia mulai paket GrowUp.');

        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(array_keys(StockAdjustment::reasonLabels()))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'counted_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'lines.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_code' => ['nullable', 'string', 'max:120'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['reason_code'] === StockAdjustment::REASON_OPNAME) {
            throw ValidationException::withMessages([
                'reason_code' => 'Alasan stock opname hanya dipakai lewat menu Stock Opname.',
            ]);
        }

        $productIds = collect($validated['lines'])->pluck('product_id')->map(fn ($id) => (int) $id);

        // Kunci duplikat memakai pasangan produk + varian agar dua varian
        // dari produk yang sama tetap boleh dikoreksi dalam satu dokumen.
        $lineKeys = collect($validated['lines'])
            ->map(fn ($row) => ((int) $row['product_id']).':'.((int) ($row['product_variant_id'] ?? 0)));

        if ($lineKeys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Terdapat produk atau varian yang sama lebih dari satu kali. Gabungkan menjadi satu baris.',
            ]);
        }

        try {
            $document = DB::transaction(function () use ($user, $store, $validated, $productIds) {
                // Urutkan penguncian berdasarkan id untuk mencegah deadlock.
                $products = Product::query()
                    ->where('store_id', $store->id)
                    ->whereIn('id', $productIds->sort()->values())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $document = StockAdjustment::create([
                    'store_id' => $store->id,
                    'type' => StockAdjustment::TYPE_ADJUSTMENT,
                    'reason_code' => $validated['reason_code'],
                    'status' => StockAdjustment::STATUS_DRAFT,
                    'notes' => $validated['notes'] ?? null,
                    'counted_at' => $validated['counted_at'] ?? now(),
                    'created_by' => $user->id,
                ]);

                $totalValue = '0.00';
                $pending = [];

                foreach ($validated['lines'] as $row) {
                    $product = $products->get((int) $row['product_id']);
                    if (! $product) {
                        throw ValidationException::withMessages([
                            'lines' => 'Salah satu produk tidak ditemukan pada toko ini.',
                        ]);
                    }

                    $variant = null;
                    if (! empty($row['product_variant_id'])) {
                        $variant = ProductVariant::query()
                            ->whereKey((int) $row['product_variant_id'])
                            ->where('product_id', $product->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $variant) {
                            throw ValidationException::withMessages([
                                'lines' => "Varian yang dipilih bukan milik produk «{$product->name}».",
                            ]);
                        }
                    }

                    AdjustmentStockOperations::assertAdjustable($store, $product, $variant);

                    $systemQty = AdjustmentStockOperations::currentQuantity($product, $variant);
                    $countedQty = ProductQuantity::normalizeStorageAmount($product, $row['counted_quantity']);
                    $variance = AdjustmentStockOperations::variance($countedQty, $systemQty);
                    $unitCost = InventoryCosting::currentAverageCost($product);
                    $batchCode = trim((string) ($row['batch_code'] ?? ''));
                    $expiryDate = $row['expiry_date'] ?? null;
                    if (PharmacyFeatures::enabled($store) && $variant === null && BatchTracking::enabled($store)
                        && ProductQuantity::cmp($variance, '0.0000') > 0 && ($batchCode === '' || $expiryDate === null)) {
                        throw ValidationException::withMessages([
                            'lines' => "Apotek: batch dan tanggal kedaluwarsa (ED) wajib untuk penyesuaian stok tambah «{$product->name}».",
                        ]);
                    }

                    $line = StockAdjustmentLine::create([
                        'stock_adjustment_id' => $document->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'batch_code' => $batchCode !== '' ? $batchCode : null,
                        'expiry_date' => $expiryDate,
                        'system_quantity' => $systemQty,
                        'counted_quantity' => $countedQty,
                        'variance_quantity' => $variance,
                        'unit_cost' => $unitCost,
                        'variance_value' => InventoryCosting::extendedCost($variance, $unitCost),
                        'line_notes' => $row['line_notes'] ?? null,
                    ]);

                    $totalValue = bcadd($totalValue, (string) $line->variance_value, 2);
                    $pending[] = [$product, $line, $variant];
                }

                $document->update(['total_variance_value' => $totalValue]);

                // Dokumen di atas ambang ditahan; stok baru berubah setelah disetujui.
                if (StockAdjustmentApproval::requiresApproval($store, $document->fresh())) {
                    $document->update(['status' => StockAdjustment::STATUS_PENDING_APPROVAL]);

                    return $document->fresh();
                }

                foreach ($pending as [$product, $line, $variant]) {
                    AdjustmentStockOperations::applyLine($store, $user, $product, $document, $line, $variant);
                }

                $document->update([
                    'status' => StockAdjustment::STATUS_POSTED,
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                ]);

                return $document->fresh();
            });

            JournalPoster::postStockAdjustment($store, $document);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'lines' => $e->getMessage(),
            ]);
        }

        $message = $document->status === StockAdjustment::STATUS_PENDING_APPROVAL
            ? "Penyesuaian stok {$document->adjustment_number} menunggu persetujuan sebelum stok berubah."
            : "Penyesuaian stok {$document->adjustment_number} berhasil diposting.";

        return redirect()->route('stock-adjustments.show', $document)->with('success', $message);
    }

    public function show(Request $request, StockAdjustment $stockAdjustment): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if ((int) $stockAdjustment->store_id !== (int) $store->id) {
            abort(403, 'Dokumen ini bukan milik toko Anda.');
        }

        $stockAdjustment->load([
            'lines.product:id,name,sku,unit',
            'lines.productVariant:id,product_id,name,sku',
            'createdBy:id,name',
            'postedBy:id,name',
            'cancelledBy:id,name',
        ]);

        $canReview = $user->isAdmin() || $user->isManager();
        $isOwnDocument = (int) $stockAdjustment->created_by === (int) $user->id;
        $blockedBySegregation = StockAdjustmentApproval::segregationOfDuties($store) && $isOwnDocument;

        return Inertia::render('StockAdjustments/Show', [
            'adjustment' => $stockAdjustment,
            'reasonLabel' => StockAdjustment::reasonLabel((string) $stockAdjustment->reason_code),
            'statusLabel' => StockAdjustment::statusLabel((string) $stockAdjustment->status),
            'canCancel' => ! $stockAdjustment->isCancelled() && $canReview,
            'canApprove' => $stockAdjustment->status === StockAdjustment::STATUS_PENDING_APPROVAL
                && $canReview
                && ! $blockedBySegregation,
            'segregationBlocked' => $stockAdjustment->status === StockAdjustment::STATUS_PENDING_APPROVAL
                && $canReview
                && $blockedBySegregation,
        ]);
    }

    /**
     * Setujui dokumen yang menunggu persetujuan, lalu terapkan selisihnya.
     */
    public function approve(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if ((int) $stockAdjustment->store_id !== (int) $store->id) {
            abort(403, 'Dokumen ini bukan milik toko Anda.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur penyesuaian stok tersedia mulai paket GrowUp.');

        try {
            DB::transaction(function () use ($user, $store, $stockAdjustment) {
                $document = StockAdjustment::query()
                    ->whereKey($stockAdjustment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                StockAdjustmentApproval::assertCanApprove($store, $document, $user);
                $document->assertCanTransitionTo(StockAdjustment::STATUS_POSTED);

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

                $document->update([
                    'status' => StockAdjustment::STATUS_POSTED,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                    'total_variance_value' => $total,
                ]);
            });

            JournalPoster::postStockAdjustment($store, $stockAdjustment->fresh());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'approval' => $e->getMessage(),
            ]);
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)
            ->with('success', "Penyesuaian stok {$stockAdjustment->adjustment_number} disetujui dan diposting.");
    }

    /**
     * Batalkan dokumen yang sudah diposting lewat mutasi pembalik.
     */
    public function cancel(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if ((int) $stockAdjustment->store_id !== (int) $store->id) {
            abort(403, 'Dokumen ini bukan milik toko Anda.');
        }

        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manajer yang dapat membatalkan penyesuaian stok.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur penyesuaian stok tersedia mulai paket GrowUp.');

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($user, $store, $stockAdjustment, $validated) {
                $document = StockAdjustment::query()
                    ->whereKey($stockAdjustment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($document->isCancelled()) {
                    throw new \RuntimeException('Dokumen ini sudah dibatalkan.');
                }

                JournalPoster::reverseStockAdjustment($store, $document);

                // Dokumen yang menunggu persetujuan belum memutasi stok,
                // sehingga penolakan cukup mengubah status.
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

        return redirect()->route('stock-adjustments.show', $stockAdjustment)
            ->with('success', "Penyesuaian stok {$stockAdjustment->adjustment_number} berhasil dibatalkan.");
    }
}
