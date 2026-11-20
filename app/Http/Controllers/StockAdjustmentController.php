<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\Accounting\JournalPoster;
use App\Support\AdjustmentStockOperations;
use App\Support\BatchTracking;
use App\Support\CostVisibility;
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
            ->whereIn('type', [StockAdjustment::TYPE_ADJUSTMENT, StockAdjustment::TYPE_REPACK])
            ->with(['createdBy:id,name', 'postedBy:id,name'])
            ->withCount('lines');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('reason_code')) {
            $query->where('reason_code', $request->input('reason_code'));
        }

        return CostVisibility::render('StockAdjustments/Index', [
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
                'id', 'name', 'sku', 'barcode', 'unit', 'stock_quantity', 'average_cost',
                'cost_price', 'allows_decimal', 'has_variants',
            ]);

        return CostVisibility::render('StockAdjustments/Create', [
            'products' => $products,
            'batchTrackingEnabled' => BatchTracking::enabled($store),
            'pharmacyFieldsEnabled' => PharmacyFeatures::enabled($store),
            'reasonLabels' => StockAdjustment::manualReasonLabels(),
            'reasonDirections' => collect(StockAdjustment::manualReasonLabels())
                ->map(fn ($label, $code) => StockAdjustment::directionFor((string) $code))
                ->all(),
            // Koreksi dari staf (gudang, kasir) menunggu persetujuan peninjau sebelum stok berubah.
            'needsReview' => ! StockAdjustmentApproval::isReviewer($user),
        ]);
    }

    /**
     * Buat dokumen sekaligus posting. Penyesuaian bersifat insidental sehingga
     * tidak perlu tahap draft seperti opname.
     *
     * Tiap baris boleh punya alasan sendiri; baris dikelompokkan per alasan menjadi
     * dokumen terpisah agar laporan per alasan tetap benar. Jumlah diisi sesuai alasan:
     * berkurang (rusak, kedaluwarsa, hilang, dipakai, sampel), bertambah (ditemukan),
     * atau stok sebenarnya (koreksi).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur penyesuaian stok tersedia mulai paket GrowUp.');

        $reasons = array_keys(StockAdjustment::manualReasonLabels());

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', Rule::in($reasons)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'lines.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.reason_code' => ['nullable', 'string', Rule::in($reasons)],
            // Jumlah berkurang/bertambah (alasan selain koreksi).
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            // Stok sebenarnya (koreksi, dan klien lama).
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_code' => ['nullable', 'string', 'max:120'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:255'],
        ], [
            'reason_code.in' => 'Alasan tidak dikenal. Stock opname dan pecah kemasan punya menu sendiri.',
            'lines.*.reason_code.in' => 'Alasan tidak dikenal. Stock opname dan pecah kemasan punya menu sendiri.',
        ]);

        // Kelompokkan baris per alasan.
        $groups = [];
        foreach ($validated['lines'] as $row) {
            $reason = $row['reason_code'] ?? $validated['reason_code'] ?? null;
            if ($reason === null) {
                throw ValidationException::withMessages(['reason_code' => 'Pilih alasan penyesuaian terlebih dahulu.']);
            }
            if (! array_key_exists('quantity', $row) || $row['quantity'] === null || $row['quantity'] === '') {
                if (! isset($row['counted_quantity']) || $row['counted_quantity'] === '') {
                    throw ValidationException::withMessages(['lines' => 'Isi jumlah untuk setiap produk.']);
                }
            }
            $groups[$reason][] = $row;
        }

        foreach ($groups as $rows) {
            $keys = collect($rows)->map(fn ($r) => ((int) $r['product_id']).':'.((int) ($r['product_variant_id'] ?? 0)));
            if ($keys->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Ada produk yang sama dua kali dengan alasan yang sama. Gabungkan menjadi satu baris.',
                ]);
            }
        }

        try {
            $documents = DB::transaction(function () use ($user, $store, $validated, $groups) {
                $docs = [];
                foreach ($groups as $reason => $rows) {
                    $docs[] = $this->createAndPost($store, $user, (string) $reason, $validated['notes'] ?? null, $rows);
                }

                return $docs;
            });

            foreach ($documents as $document) {
                JournalPoster::postStockAdjustment($store, $document);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'lines' => $e->getMessage(),
            ]);
        }

        if (count($documents) > 1) {
            $numbers = collect($documents)->pluck('adjustment_number')->implode(', ');
            $pending = collect($documents)->contains(fn ($d) => $d->status === StockAdjustment::STATUS_PENDING_APPROVAL);

            return redirect()->route('stock-adjustments.index')->with('success', count($documents)
                ." dokumen dibuat per alasan: {$numbers}."
                .($pending ? ' Sebagian menunggu persetujuan sebelum stok berubah.' : ''));
        }

        $document = $documents[0];
        $message = $document->status === StockAdjustment::STATUS_PENDING_APPROVAL
            ? "Penyesuaian stok {$document->adjustment_number} menunggu persetujuan sebelum stok berubah."
            : "Penyesuaian stok {$document->adjustment_number} berhasil diposting.";

        return redirect()->route('stock-adjustments.show', $document)->with('success', $message);
    }

    /**
     * Satu dokumen untuk satu alasan. Dipanggil di dalam transaksi.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function createAndPost($store, $user, string $reason, ?string $notes, array $rows): StockAdjustment
    {
        $mode = StockAdjustment::directionFor($reason);
        $productIds = collect($rows)->pluck('product_id')->map(fn ($id) => (int) $id);

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
            'reason_code' => $reason,
            'status' => StockAdjustment::STATUS_DRAFT,
            'notes' => $notes,
            'counted_at' => now(),
            'created_by' => $user->id,
        ]);

        $totalValue = '0.00';
        $pending = [];

        foreach ($rows as $row) {
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

            $label = $product->name.($variant ? ' — '.$variant->name : '');
            $systemQty = AdjustmentStockOperations::currentQuantity($product, $variant);
            $countedQty = $this->countedFromRow($product, $row, $mode, $systemQty, $label);
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

        if (collect($pending)->every(fn ($p) => bccomp((string) $p[1]->variance_quantity, '0', ProductQuantity::SCALE) === 0)) {
            throw ValidationException::withMessages([
                'lines' => 'Tidak ada perubahan stok. Isi jumlah yang berkurang, bertambah, atau stok sebenarnya.',
            ]);
        }

        $document->update(['total_variance_value' => $totalValue]);

        // Dokumen dari staf, atau di atas ambang, ditahan; stok baru berubah setelah disetujui.
        if (StockAdjustmentApproval::requiresApproval($store, $document->fresh(), $user)) {
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
    }

    /**
     * Stok sebenarnya dari isian baris: berkurang, bertambah, atau langsung stok sebenarnya.
     *
     * @param  array<string, mixed>  $row
     */
    private function countedFromRow(Product $product, array $row, string $mode, string $systemQty, string $label): string
    {
        $hasQuantity = isset($row['quantity']) && $row['quantity'] !== '';

        // Klien lama / koreksi: isian berupa stok sebenarnya.
        if ($mode === StockAdjustment::DIRECTION_SET || ! $hasQuantity) {
            $raw = $row['counted_quantity'] ?? $row['quantity'] ?? null;

            return ProductQuantity::normalizeStorageAmount($product, $raw);
        }

        $qty = ProductQuantity::normalizeStorageAmount($product, $row['quantity']);
        if (bccomp($qty, '0', ProductQuantity::SCALE) <= 0) {
            throw ValidationException::withMessages(['lines' => "Isi jumlah untuk «{$label}»."]);
        }

        if ($mode === StockAdjustment::DIRECTION_INCREASE) {
            return bcadd($systemQty, $qty, ProductQuantity::SCALE);
        }

        if (bccomp($qty, $systemQty, ProductQuantity::SCALE) > 0) {
            $sisa = rtrim(rtrim($systemQty, '0'), '.') ?: '0';
            throw ValidationException::withMessages([
                'lines' => "Stok «{$label}» hanya {$sisa}{$this->unitSuffix($product)}, tidak bisa dikurangi "
                    .(rtrim(rtrim($qty, '0'), '.') ?: '0').'. Bila stok tercatat memang salah, pilih alasan "Koreksi salah input".',
            ]);
        }

        return bcsub($systemQty, $qty, ProductQuantity::SCALE);
    }

    private function unitSuffix(Product $product): string
    {
        return $product->unit ? ' '.$product->unit : '';
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

        $canReview = StockAdjustmentApproval::isReviewer($user);
        $isOwnDocument = (int) $stockAdjustment->created_by === (int) $user->id;
        $blockedBySegregation = StockAdjustmentApproval::segregationOfDuties($store) && $isOwnDocument;

        return CostVisibility::render('StockAdjustments/Show', [
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

        if (! StockAdjustmentApproval::isReviewer($user)) {
            abort(403, 'Hanya admin, manajer, atau pemilik yang dapat membatalkan penyesuaian stok.');
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
