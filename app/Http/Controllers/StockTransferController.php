<?php

namespace App\Http\Controllers;

use App\Actions\ResolveTransferDiscrepancies;
use App\Actions\StockRequestWorkflow;
use App\Actions\TransferReceiptCorrections;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferCorrection;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\CostVisibility;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use App\Support\TransferReceiving;
use App\Support\TransferStockOperations;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class StockTransferController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $query = StockTransfer::query()
            ->where(function ($q) use ($store) {
                $q->where('from_store_id', $store->id)->orWhere('to_store_id', $store->id);
            })
            ->with(['fromStore:id,name', 'toStore:id,name', 'user:id,name'])
            ->withCount('lines')
            // F5/F8: jumlah barang yang diterima kurang, rusak, atau lebih dari yang dikirim.
            ->withCount(['lines as discrepancy_lines_count' => fn ($q) => $this->scopeDiscrepancyLines($q)])
            // F8: selisih yang masih menunggu keputusan lokasi pengirim.
            ->withCount(['lines as open_discrepancy_lines_count' => fn ($q) => $q->whereNotNull('discrepancy_type')->whereNull('resolution')])
            // F9: pengajuan koreksi penerimaan yang menunggu keputusan pengirim.
            ->withCount(['corrections as pending_corrections_count' => fn ($q) => $q->where('status', StockTransferCorrection::STATUS_PENDING)]);

        if ($request->boolean('discrepancy')) {
            $query->whereHas('lines', fn ($q) => $this->scopeDiscrepancyLines($q));
        }

        // F8: hanya transfer keluar dari toko ini yang selisihnya belum diselesaikan.
        if ($request->boolean('needs_resolution')) {
            $this->scopeNeedsDecision($query, (int) $store->id);
        }

        if ($request->filled('status') && in_array($request->input('status'), ['draft', 'sent', 'received', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        // Arah dilihat dari toko aktif. "incoming" = masih menunggu diterima di toko ini.
        $direction = in_array($request->input('direction'), ['in', 'out', 'incoming'], true) ? $request->input('direction') : null;
        match ($direction) {
            'in' => $query->where('to_store_id', $store->id),
            'out' => $query->where('from_store_id', $store->id),
            'incoming' => $query->where('to_store_id', $store->id)->where('status', StockTransfer::STATUS_SENT),
            default => null,
        };

        $transfers = $query
            // Yang menunggu diterima di toko ini selalu di paling atas.
            ->orderByRaw('CASE WHEN to_store_id = ? AND status = ? THEN 0 ELSE 1 END', [$store->id, StockTransfer::STATUS_SENT])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $incomingCount = StockTransfer::query()
            ->where('to_store_id', $store->id)
            ->where('status', StockTransfer::STATUS_SENT)
            ->count();

        return CostVisibility::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'currentStore' => ['id' => $store->id, 'name' => $store->name],
            'canCreateTransfer' => StorePlanLimits::allowsAdvancedRetailFeatures($store),
            'statusFilter' => $request->input('status'),
            'directionFilter' => $direction,
            'discrepancyFilter' => $request->boolean('discrepancy'),
            'needsResolutionFilter' => $request->boolean('needs_resolution'),
            'incomingCount' => $incomingCount,
            'needsResolutionCount' => $this->scopeNeedsDecision(StockTransfer::query(), (int) $store->id)->count(),
        ]);
    }

    /** Transfer keluar dari toko ini yang menunggu keputusannya: selisih terbuka atau pengajuan koreksi. */
    private function scopeNeedsDecision($query, int $storeId)
    {
        return $query->where('from_store_id', $storeId)
            ->where('status', StockTransfer::STATUS_RECEIVED)
            ->where(function ($q) {
                $q->whereHas('lines', fn ($l) => $l->whereNotNull('discrepancy_type')->whereNull('resolution'))
                    ->orWhereHas('corrections', fn ($c) => $c->where('status', StockTransferCorrection::STATUS_PENDING));
            });
    }

    /** Baris yang diterima tidak sesuai jumlah kirim (termasuk selisih lama tanpa jenis). */
    private function scopeDiscrepancyLines($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('discrepancy_type')
                ->orWhere(fn ($short) => $short->whereNotNull('quantity_received')->whereColumn('quantity_received', '<', 'quantity'));
        });
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('stock-transfers.index')
                ->with('warning', 'Paket Starter tidak mencakup transfer stok antar outlet. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $store->load('organization:id,name');

        $destStores = $this->destinationStoresForUser($user, $store);
        if ($destStores->isEmpty()) {
            $hint = $store->organization_id === null
                ? 'Toko aktif belum masuk grup usaha. Buat outlet baru dengan opsi «Gabung grup» atau «Grup baru», lalu pilih outlet dalam grup yang sama.'
                : 'Tidak ada outlet lain dalam grup usaha ini. Pastikan toko tujuan memakai organisasi yang sama dan Anda punya akses ke sana.';

            return redirect()->route('stock-transfers.index')->with('warning', $hint);
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('track_stock', true)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'unit', 'stock_quantity', 'allows_decimal']);

        return CostVisibility::render('StockTransfers/Create', [
            'sourceStore' => [
                'id' => $store->id,
                'name' => $store->name,
                'organization_name' => $store->organization?->name,
            ],
            'destStores' => $destStores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'products' => $products,
            // Boleh langsung kirim (stok asal berkurang) tanpa membuka draft dulu.
            'canSendNow' => $this->canOperateAt($user, (int) $store->id),
        ]);
    }

    public function match(Request $request): JsonResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            abort(403, 'Paket Starter tidak mencakup transfer stok antar outlet.');
        }

        $data = $request->validate([
            'to_store_id' => ['required', 'integer', 'exists:stores,id'],
            'from_product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $toStore = Store::query()->whereKey($data['to_store_id'])->firstOrFail();
        if (! $user->isMemberOfStore($toStore) || ! $toStore->is_active) {
            // Admin/manager/warehouse boleh transfer antar toko dalam satu grup.
            if (! $this->canTransferToStore($user, $store, $toStore)) {
                abort(403);
            }
        }
        if ((int) $toStore->id === (int) $store->id) {
            abort(422, 'Toko tujuan harus berbeda dengan toko aktif.');
        }
        if (! $this->storesShareOrganization($store, $toStore)) {
            abort(403, 'Transfer hanya boleh antar outlet dalam satu grup usaha.');
        }

        $from = Product::query()
            ->where('store_id', $store->id)
            ->whereKey($data['from_product_id'])
            ->firstOrFail();

        $to = $this->findDestinationProduct($toStore, $from);

        return response()->json([
            'matched' => $to !== null,
            'to_product' => $to ? [
                'id' => $to->id,
                'name' => $to->name,
                'sku' => $to->sku,
                'stock_quantity' => (string) $to->stock_quantity,
                'allows_decimal' => (bool) $to->allows_decimal,
            ] : null,
        ]);
    }

    /**
     * Simpan draft transfer — tanpa mutasi stok.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $fromStore = $user->store;
        if (! $fromStore) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($fromStore)) {
            throw ValidationException::withMessages([
                'lines' => 'Paket Starter tidak mencakup transfer stok antar outlet.',
            ]);
        }

        $validated = $request->validate([
            'to_store_id' => ['required', 'integer', 'exists:stores,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.from_product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'send_now' => ['sometimes', 'boolean'],
        ]);

        $toStore = Store::query()->whereKey($validated['to_store_id'])->firstOrFail();

        if (! $user->isMemberOfStore($fromStore) || ! $user->isMemberOfStore($toStore)) {
            if (! $this->canTransferToStore($user, $fromStore, $toStore)) {
                abort(403, 'Anda tidak punya akses ke salah satu toko dalam transfer.');
            }
        }
        if (! $fromStore->is_active || ! $toStore->is_active) {
            throw ValidationException::withMessages(['to_store_id' => 'Salah satu toko tidak aktif.']);
        }
        if ((int) $fromStore->id === (int) $toStore->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Pilih outlet tujuan yang berbeda.']);
        }
        if (! $this->storesShareOrganization($fromStore, $toStore)) {
            throw ValidationException::withMessages([
                'to_store_id' => 'Transfer stok hanya diperbolehkan antar toko dalam grup usaha yang sama.',
            ]);
        }

        $fromIds = collect($validated['lines'])->pluck('from_product_id');
        if ($fromIds->count() !== $fromIds->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Produk yang sama tidak boleh duplikat. Gabung jumlahnya dalam satu baris.']);
        }

        try {
            $transfer = DB::transaction(function () use ($user, $fromStore, $toStore, $validated) {
                $sortedFromIds = collect($validated['lines'])->pluck('from_product_id')->sort()->values();
                $lockedFrom = Product::query()
                    ->whereIn('id', $sortedFromIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $resolved = [];
                foreach ($validated['lines'] as $idx => $row) {
                    $from = $lockedFrom->get($row['from_product_id']);
                    if (! $from || (int) $from->store_id !== (int) $fromStore->id) {
                        throw ValidationException::withMessages(["lines.{$idx}.from_product_id" => 'Produk tidak valid untuk toko asal.']);
                    }
                    if (! $from->track_stock || $from->has_variants || $from->is_bundle || ! $from->is_active) {
                        throw ValidationException::withMessages(["lines.{$idx}.from_product_id" => 'Produk tidak didukung untuk transfer.']);
                    }

                    $qty = ProductQuantity::normalizeLineQuantity($from, $row['quantity']);

                    // Validasi stok mencukupi saat pembuatan draft (soft check).
                    if (ProductQuantity::lt((string) $from->stock_quantity, $qty)) {
                        throw ValidationException::withMessages(["lines.{$idx}.quantity" => 'Stok asal tidak mencukupi.']);
                    }

                    $to = $this->findDestinationProduct($toStore, $from);
                    if (! $to) {
                        throw ValidationException::withMessages(["lines.{$idx}.from_product_id" => 'Tidak ada produk di outlet tujuan dengan SKU/barcode yang cocok.']);
                    }
                    if (! $to->allows_decimal && ProductQuantity::cmp($qty, (string) (int) floor((float) $qty)) !== 0) {
                        throw ValidationException::withMessages(["lines.{$idx}.quantity" => 'Produk tujuan tidak mendukung kuantitas desimal.']);
                    }

                    // Validasi satuan dasar kompatibel.
                    $fromBaseUnit = $from->baseUnit();
                    $toBaseUnit = $to->baseUnit();
                    if ($fromBaseUnit !== $toBaseUnit) {
                        throw ValidationException::withMessages(["lines.{$idx}.from_product_id" => "Satuan dasar tidak cocok (asal: {$fromBaseUnit}, tujuan: {$toBaseUnit})."]);
                    }

                    $resolved[] = [
                        'from' => $from,
                        'to' => $to,
                        'qty' => $qty,
                    ];
                }

                // Simpan snapshot biaya asal saat draft dibuat.
                $transfer = StockTransfer::create([
                    'from_store_id' => $fromStore->id,
                    'to_store_id' => $toStore->id,
                    'user_id' => $user->id,
                    'notes' => $validated['notes'] ?? null,
                    'status' => StockTransfer::STATUS_DRAFT,
                ]);

                foreach ($resolved as $r) {
                    $from = $r['from'];
                    $to = $r['to'];
                    $qty = $r['qty'];
                    $averageCost = InventoryCosting::currentAverageCost($from);
                    $totalCost = InventoryCosting::extendedCost($qty, $averageCost, 2);

                    StockTransferLine::create([
                        'stock_transfer_id' => $transfer->id,
                        'from_product_id' => $from->id,
                        'to_product_id' => $to->id,
                        'quantity' => $qty,
                        'base_unit' => $from->baseUnit(),
                        'unit_cost_snapshot' => $averageCost,
                        'total_cost_snapshot' => $totalCost,
                    ]);
                }

                return $transfer;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', $e->getMessage());
        }

        // "Kirim sekarang": langsung kirim draft yang baru dibuat (stok asal berkurang).
        if ($request->boolean('send_now') && $this->canOperateAt($user, (int) $transfer->from_store_id)) {
            try {
                return $this->send($request, $transfer);
            } catch (ValidationException $e) {
                return redirect()->route('stock-transfers.show', $transfer)
                    ->with('error', 'Draft tersimpan, tetapi belum bisa dikirim: '.collect($e->errors())->flatten()->first());
            }
        }

        return redirect()->route('stock-transfers.show', $transfer)
            ->with('success', 'Draft transfer berhasil disimpan. Stok belum berubah — kirim saat siap.');
    }

    public function show(Request $request, StockTransfer $stockTransfer): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! $this->canAccessTransfer($user, $stockTransfer)) {
            abort(403);
        }

        $stockTransfer->load([
            'fromStore:id,name',
            'toStore:id,name',
            'user:id,name',
            'sentBy:id,name',
            'receivedBy:id,name',
            'cancelledBy:id,name',
            'lines.fromProduct:id,name,sku,unit,base_unit',
            'lines.toProduct:id,name,sku,unit,base_unit',
            'lines.resolvedBy:id,name',
            'followupOf:id,transfer_number',
            'followups:id,transfer_number,status,followup_of_id',
            'corrections.requester:id,name',
            'corrections.decider:id,name',
            'corrections.lines',
        ]);
        // Tanda tangan penerima hanya dikirim ke halaman detail.
        $stockTransfer->makeVisible('receiver_signature');

        $canSend = $stockTransfer->isDraft() && $this->canOperateAt($user, (int) $stockTransfer->from_store_id);
        $canReceive = $stockTransfer->isSent() && $this->canOperateAt($user, (int) $stockTransfer->to_store_id);
        $canCancel = ($stockTransfer->isDraft() || $stockTransfer->isSent())
            && ($this->canOperateAt($user, (int) $stockTransfer->from_store_id)
                || $this->canOperateAt($user, (int) $stockTransfer->to_store_id));

        $hasPendingCorrection = $stockTransfer->corrections->contains(fn (StockTransferCorrection $c) => $c->isPending());
        // Relasi toStore di atas hanya memuat id & nama; aturan penerimaan butuh pengaturan tokonya.
        $receivingStore = Store::query()->find($stockTransfer->to_store_id);
        // Baris yang jumlah diterimanya masih boleh dikoreksi lewat pengajuan (F9).
        $correctableLineIds = $stockTransfer->isReceived()
            ? $stockTransfer->lines
                ->filter(fn (StockTransferLine $line) => ! $line->isLockedForCorrection()
                    && ! ($receivingStore && TransferStockOperations::hasBatchAllocations($receivingStore, $line)))
                ->pluck('id')->values()->all()
            : [];

        return CostVisibility::render('StockTransfers/Show', [
            'transfer' => $stockTransfer,
            'canSend' => $canSend,
            'canReceive' => $canReceive,
            'canCancel' => $canCancel,
            // F8: selisih diputuskan oleh lokasi pengirim.
            'canResolve' => $stockTransfer->isReceived()
                && ! $hasPendingCorrection
                && $stockTransfer->lines->contains(fn (StockTransferLine $line) => $line->needsResolution())
                && $this->canOperateAt($user, (int) $stockTransfer->from_store_id),
            // F9: penerimaan buta — penerima menghitung dulu tanpa melihat jumlah kirim.
            'blindReceiving' => $canReceive && TransferReceiving::blindEnabled($receivingStore),
            // F9: koreksi setelah diterima. Diajukan penerima, diputuskan pengirim.
            'canRequestCorrection' => TransferReceiving::withinCorrectionWindow($stockTransfer)
                && ! $hasPendingCorrection
                && $correctableLineIds !== []
                && $this->canOperateAt($user, (int) $stockTransfer->to_store_id),
            'canDecideCorrection' => $hasPendingCorrection && $this->canOperateAt($user, (int) $stockTransfer->from_store_id),
            'canCancelCorrection' => $hasPendingCorrection && $this->canOperateAt($user, (int) $stockTransfer->to_store_id),
            'correctableLineIds' => $correctableLineIds,
            'correctionDeadline' => TransferReceiving::correctionDeadline($stockTransfer)?->toIso8601String(),
            'correctionWindowHours' => TransferReceiving::CORRECTION_WINDOW_HOURS,
        ]);
    }

    /**
     * F4 — Surat jalan (PDF) untuk transfer yang sudah dikirim atau diterima.
     * Hanya anggota lokasi asal/tujuan (owner grup otomatis anggota semua outlet).
     */
    public function deliveryNote(Request $request, StockTransfer $stockTransfer): SymfonyResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $this->canAccessTransfer($user, $stockTransfer)) {
            abort(403);
        }

        if (! $stockTransfer->isSent() && $stockTransfer->status !== StockTransfer::STATUS_RECEIVED) {
            return back()->with('error', 'Surat jalan baru bisa dicetak setelah transfer dikirim.');
        }

        $stockTransfer->load([
            'fromStore:id,name,address,phone,timezone',
            'toStore:id,name,address,phone',
            'user:id,name',
            'sentBy:id,name',
            'receivedBy:id,name',
            'lines.fromProduct:id,name,sku,base_unit',
            'lines.toProduct:id,name,sku,base_unit',
        ]);
        $stockTransfer->makeVisible('receiver_signature');

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'transfer' => $stockTransfer,
            'statusLabel' => $stockTransfer->isSent() ? 'Dikirim' : 'Diterima',
            'timezone' => $stockTransfer->fromStore?->timezone ?: config('app.timezone'),
        ])->setPaper('a4');

        // Setelah diterima, dokumen yang sama menjadi Bukti Terima (memuat tanda tangan penerima).
        $prefix = $stockTransfer->isReceived() ? 'bukti-terima-' : 'surat-jalan-';

        return $pdf->download($prefix.$stockTransfer->transfer_number.'.pdf');
    }

    /**
     * F8 — Lokasi pengirim memutuskan selisih penerimaan (kurang/rusak/lebih).
     */
    public function resolveDiscrepancies(Request $request, StockTransfer $stockTransfer, ResolveTransferDiscrepancies $resolver): RedirectResponse
    {
        $user = $request->user();

        if (! $this->canAccessTransfer($user, $stockTransfer, 'from')
            || ! $this->canOperateAt($user, (int) $stockTransfer->from_store_id)) {
            abort(403, 'Hanya owner, admin, manajer, atau admin gudang lokasi pengirim yang dapat menyelesaikan selisih.');
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.resolution' => ['required', 'string', 'in:'.implode(',', array_keys(StockTransferLine::RESOLUTION_LABELS))],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.resend' => ['nullable', 'boolean'],
        ], [
            'lines.required' => 'Pilih penyelesaian untuk minimal satu barang.',
            'lines.*.resolution.required' => 'Pilih penyelesaian untuk tiap barang yang dicentang.',
        ]);

        if ($stockTransfer->corrections()->where('status', StockTransferCorrection::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages([
                'lines' => 'Ada pengajuan koreksi penerimaan yang menunggu. Putuskan pengajuan itu dulu, baru selesaikan selisihnya.',
            ]);
        }

        $decisions = [];
        foreach ($validated['lines'] as $row) {
            $decisions[(int) $row['id']] = [
                'resolution' => $row['resolution'],
                'note' => $row['note'] ?? null,
                'resend' => (bool) ($row['resend'] ?? false),
            ];
        }

        try {
            $result = $resolver->handle($stockTransfer, $user, $decisions);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        $message = "Selisih {$result['resolved']} barang sudah diselesaikan.";
        if ($result['followup']) {
            $message .= " Draft kiriman susulan {$result['followup']->transfer_number} dibuat, kirim saat barangnya siap.";
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('success', $message);
    }

    /**
     * Kirim draft — kurangi stok sumber sekali.
     */
    public function send(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! $this->canAccessTransfer($user, $stockTransfer, 'from')) {
            abort(403, 'Hanya anggota toko asal yang dapat mengirim transfer.');
        }

        // Owner, admin, manajer, atau admin gudang di toko ASAL yang boleh mengirim.
        if (! $this->canOperateAt($user, (int) $stockTransfer->from_store_id)) {
            abort(403, 'Hanya owner, admin, manajer, atau admin gudang toko asal yang dapat mengirim transfer.');
        }

        try {
            DB::transaction(function () use ($user, $stockTransfer) {
                // Kunci baris transfer sungguhan (status dibaca ulang) supaya aksi ganda tidak memotong stok dua kali.
                $stockTransfer = StockTransfer::query()->whereKey($stockTransfer->id)->lockForUpdate()->firstOrFail();
                $stockTransfer->assertCanTransitionTo(StockTransfer::STATUS_SENT);

                $fromStore = $stockTransfer->fromStore;
                $lines = $stockTransfer->lines()->with('fromProduct')->get();

                // Kunci seluruh produk asal secara berurutan.
                $fromIds = $lines->pluck('from_product_id')->sort()->values();
                Product::query()->whereIn('id', $fromIds)->orderBy('id')->lockForUpdate()->get();

                foreach ($lines as $line) {
                    $product = $line->fromProduct;
                    $qty = (string) $line->quantity;

                    // Re-validasi stok sebelum kirim.
                    $product->refresh();
                    if (ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                        throw ValidationException::withMessages([
                            'lines' => "Stok «{$product->name}» tidak mencukupi saat dikirim (tersisa {$product->stock_quantity}).",
                        ]);
                    }

                    // Re-snapshot biaya agar mendekati kondisi aktual.
                    $averageCost = InventoryCosting::currentAverageCost($product);
                    $totalCost = InventoryCosting::extendedCost($qty, $averageCost, 2);
                    $line->update([
                        'unit_cost_snapshot' => $averageCost,
                        'total_cost_snapshot' => $totalCost,
                    ]);

                    TransferStockOperations::deductAtSource($fromStore, $user, $product, $qty, $line);
                }

                $stockTransfer->update([
                    'status' => StockTransfer::STATUS_SENT,
                    'sent_at' => now(),
                    'sent_by' => $user->id,
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', 'Transfer berhasil dikirim. Menunggu penerimaan outlet tujuan.');
    }

    /**
     * Terima transfer — tambah stok tujuan + weighted average cost.
     */
    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! $this->canAccessTransfer($user, $stockTransfer, 'to')) {
            abort(403, 'Hanya anggota toko tujuan yang dapat menerima transfer.');
        }

        // Owner, admin, manajer, atau admin gudang di toko TUJUAN yang boleh menerima.
        if (! $this->canOperateAt($user, (int) $stockTransfer->to_store_id)) {
            abort(403, 'Hanya owner, admin, manajer, atau admin gudang toko tujuan yang dapat menerima transfer.');
        }

        // F5: jumlah diterima per baris (opsional). Tanpa isian = diterima penuh (perilaku lama).
        // F8: nama + tanda tangan penerima wajib, sebagai bukti serah terima.
        $validated = $request->validate([
            'receiver_name' => ['required', 'string', 'max:120'],
            'receiver_signature' => ['required', 'string', 'max:400000', 'starts_with:data:image/png;base64,'],
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'lines.*.discrepancy_type' => ['nullable', 'string', 'in:'.StockTransferLine::DISCREPANCY_SHORT.','.StockTransferLine::DISCREPANCY_DAMAGED],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
            // F9: foto bukti selisih (opsional), hanya disimpan untuk baris yang berselisih.
            'lines.*.photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ], [
            'lines.*.photo.image' => 'Foto bukti harus berupa gambar.',
            'lines.*.photo.mimes' => 'Foto bukti harus berformat JPG, PNG, atau WebP.',
            'lines.*.photo.max' => 'Foto bukti maksimal 5 MB per barang.',
            'receiver_name.required' => 'Isi nama penerima barang.',
            'receiver_signature.required' => 'Tanda tangan penerima wajib diisi.',
            'receiver_signature.starts_with' => 'Tanda tangan tidak terbaca. Ulangi tanda tangan.',
            'receiver_signature.max' => 'Tanda tangan terlalu besar. Hapus lalu ulangi tanda tangan.',
            'lines.*.quantity_received.min' => 'Jumlah diterima tidak boleh negatif.',
        ]);
        $signature = $validated['receiver_signature'];
        $signatureBytes = base64_decode(substr($signature, strlen('data:image/png;base64,')), true);
        if ($signatureBytes === false || ! str_starts_with($signatureBytes, "\x89PNG")) {
            throw ValidationException::withMessages(['receiver_signature' => 'Tanda tangan tidak terbaca. Ulangi tanda tangan.']);
        }
        $receiverName = trim($validated['receiver_name']);
        $receivedInput = collect($validated['lines'] ?? [])->keyBy(fn ($row) => (int) $row['id']);
        $photoInput = collect($validated['lines'] ?? [])
            ->filter(fn ($row) => ($row['photo'] ?? null) instanceof \Illuminate\Http\UploadedFile)
            ->mapWithKeys(fn ($row) => [(int) $row['id'] => $row['photo']]);

        $discrepancyCount = 0;
        $storedPhotos = [];

        try {
            DB::transaction(function () use ($user, $stockTransfer, $receivedInput, $photoInput, $receiverName, $signature, &$discrepancyCount, &$storedPhotos) {
                // Kunci baris transfer sungguhan supaya tidak bisa diterima dua kali bersamaan.
                $stockTransfer = StockTransfer::query()->whereKey($stockTransfer->id)->lockForUpdate()->firstOrFail();
                $stockTransfer->assertCanTransitionTo(StockTransfer::STATUS_RECEIVED);

                $toStore = $stockTransfer->toStore;
                $lines = $stockTransfer->lines()->with('toProduct')->get();

                $unknownIds = $receivedInput->keys()->diff($lines->pluck('id'));
                if ($unknownIds->isNotEmpty()) {
                    throw ValidationException::withMessages(['lines' => 'Ada baris yang bukan bagian dari transfer ini.']);
                }

                // Kunci seluruh produk tujuan secara berurutan.
                $toIds = $lines->pluck('to_product_id')->sort()->values();
                Product::query()->whereIn('id', $toIds)->orderBy('id')->lockForUpdate()->get();

                foreach ($lines as $line) {
                    $product = $line->toProduct;
                    $product->refresh();
                    $sentQty = (string) $line->quantity;
                    $qty = $sentQty;
                    $notes = null;

                    $input = $receivedInput->get($line->id);
                    if ($input !== null) {
                        // Jumlah yang benar-benar tiba dalam kondisi baik.
                        $arrived = ProductQuantity::normalizeStorageAmount($product, $input['quantity_received']);
                        $reason = trim((string) ($input['discrepancy_reason'] ?? ''));
                        $isShort = ProductQuantity::cmp($arrived, $sentQty) < 0;
                        $isOver = ProductQuantity::cmp($arrived, $sentQty) > 0;

                        if ($isShort && $reason === '') {
                            throw ValidationException::withMessages([
                                'lines' => "Tulis alasan selisih untuk «{$product->name}» (mis. rusak, tumpah, kurang dari gudang).",
                            ]);
                        }
                        if ($isShort && TransferStockOperations::hasBatchAllocations($toStore, $line)) {
                            throw ValidationException::withMessages([
                                'lines' => "«{$product->name}» memakai batch/ED, jadi harus diterima penuh. Catat kerusakan lewat Penyesuaian Stok.",
                            ]);
                        }
                        if ($isOver && TransferStockOperations::hasBatchAllocations($toStore, $line)) {
                            throw ValidationException::withMessages([
                                'lines' => "«{$product->name}» memakai batch/ED, jadi jumlah diterima tidak boleh melebihi yang dikirim. Minta pengirim membuat transfer baru untuk kelebihannya.",
                            ]);
                        }

                        if ($isOver) {
                            // Kelebihan belum masuk stok: menunggu pengirim mengakui atau menariknya kembali.
                            $over = ProductQuantity::sub($arrived, $sentQty);
                            $line->update([
                                'quantity_received' => $sentQty,
                                'discrepancy_type' => StockTransferLine::DISCREPANCY_OVER,
                                'quantity_over' => $over,
                                'discrepancy_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                            ]);
                            $discrepancyCount++;
                            $notes = mb_substr(
                                "{$stockTransfer->transfer_number} · datang {$arrived} dari {$sentQty}, lebih {$over} menunggu konfirmasi pengirim",
                                0,
                                255,
                            );
                        } else {
                            // (foto bukti disimpan di bawah, setelah jenis selisih baris ditetapkan)
                            $qty = $arrived;
                            $type = $isShort
                                ? (($input['discrepancy_type'] ?? null) ?: StockTransferLine::DISCREPANCY_SHORT)
                                : null;
                            $line->update([
                                'quantity_received' => $qty,
                                'discrepancy_type' => $type,
                                'quantity_over' => null,
                                'discrepancy_reason' => $isShort ? mb_substr($reason, 0, 255) : null,
                            ]);

                            if ($isShort) {
                                $discrepancyCount++;
                                $short = ProductQuantity::sub($sentQty, $qty);
                                $label = mb_strtolower(StockTransferLine::DISCREPANCY_LABELS[$type]);
                                $notes = mb_substr(
                                    "{$stockTransfer->transfer_number} · diterima {$qty} dari {$sentQty}, {$label} {$short}: {$reason}",
                                    0,
                                    255,
                                );
                            }
                        }
                    }

                    // F9: foto bukti hanya untuk baris yang berselisih.
                    if ($line->discrepancy_type !== null && $photoInput->has($line->id)) {
                        $path = $photoInput->get($line->id)->store('transfer-discrepancies', 'public');
                        $storedPhotos[] = $path;
                        $line->update(['discrepancy_photo' => $path]);
                    }

                    // Seluruh barang hilang/rusak: tidak ada stok yang masuk.
                    if (ProductQuantity::cmp($qty, '0') <= 0) {
                        continue;
                    }

                    $unitCostSource = (string) ($line->unit_cost_snapshot ?? InventoryCosting::currentAverageCost($product));

                    // Tambah stok tujuan.
                    TransferStockOperations::addAtDestination($toStore, $user, $product, $qty, $line, $notes);

                    // Update average cost tujuan menggunakan weighted average.
                    $product->refresh();
                    $newAverageCost = InventoryCosting::weightedAverage(
                        bcsub((string) $product->stock_quantity, $qty, ProductQuantity::SCALE),
                        InventoryCosting::currentAverageCost($product),
                        $qty,
                        $unitCostSource,
                    );
                    $product->update([
                        'average_cost' => $newAverageCost,
                        'cost_price' => round((float) $newAverageCost, 2),
                    ]);
                }

                $stockTransfer->update([
                    'status' => StockTransfer::STATUS_RECEIVED,
                    'received_at' => now(),
                    'received_by' => $user->id,
                    'receiver_name' => mb_substr($receiverName, 0, 120),
                    'receiver_signature' => $signature,
                    // F9: ditentukan dari pengaturan lokasi penerima, bukan dari kiriman browser.
                    'received_blind' => TransferReceiving::blindEnabled($toStore),
                ]);

                // F6: permintaan stok asal transfer ini menjadi Selesai.
                StockRequestWorkflow::syncFromTransfer($stockTransfer);
            });
        } catch (ValidationException $e) {
            Storage::disk('public')->delete($storedPhotos);
            throw $e;
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($storedPhotos);
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', $discrepancyCount > 0
                ? "Transfer diterima dengan selisih pada {$discrepancyCount} barang. Stok bertambah sesuai jumlah yang benar-benar tiba; selisihnya menunggu ditindaklanjuti lokasi pengirim."
                : 'Transfer berhasil diterima. Stok outlet telah bertambah.');
    }

    /**
     * F9 — Penerima mengajukan koreksi jumlah diterima setelah penerimaan ditandatangani.
     */
    public function requestCorrection(Request $request, StockTransfer $stockTransfer, TransferReceiptCorrections $corrections): RedirectResponse
    {
        $user = $request->user();
        if (! $this->canAccessTransfer($user, $stockTransfer, 'to')
            || ! $this->canOperateAt($user, (int) $stockTransfer->to_store_id)) {
            abort(403, 'Hanya owner, admin, manajer, atau admin gudang lokasi penerima yang dapat mengajukan koreksi.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'lines.*.discrepancy_type' => ['nullable', 'string', 'in:'.StockTransferLine::DISCREPANCY_SHORT.','.StockTransferLine::DISCREPANCY_DAMAGED],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [
            'reason.required' => 'Tulis alasan koreksi.',
            'reason.min' => 'Alasan koreksi minimal 5 karakter.',
            'lines.required' => 'Ubah jumlah diterima minimal satu barang.',
        ]);

        try {
            $correction = $corrections->request($stockTransfer, $user, $validated['reason'], $validated['lines']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', 'Koreksi untuk '.$correction->lines()->count().' barang diajukan. Menunggu keputusan '.($stockTransfer->fromStore?->name ?? 'pengirim').'.');
    }

    /**
     * F9 — Putusan atas pengajuan koreksi: pengirim menyetujui/menolak, penerima boleh membatalkan.
     */
    public function decideCorrection(Request $request, StockTransfer $stockTransfer, StockTransferCorrection $correction, string $decision, TransferReceiptCorrections $corrections): RedirectResponse
    {
        $user = $request->user();
        abort_unless((int) $correction->stock_transfer_id === (int) $stockTransfer->id, 404);

        $side = $decision === 'cancel' ? 'to' : 'from';
        $storeId = (int) ($side === 'to' ? $stockTransfer->to_store_id : $stockTransfer->from_store_id);
        if (! $this->canAccessTransfer($user, $stockTransfer, $side) || ! $this->canOperateAt($user, $storeId)) {
            abort(403, $side === 'to'
                ? 'Hanya lokasi penerima yang dapat membatalkan pengajuan koreksi.'
                : 'Hanya owner, admin, manajer, atau admin gudang lokasi pengirim yang dapat memutuskan koreksi.');
        }

        $validated = $request->validate([
            'note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['note.required' => 'Tulis alasan penolakan.']);

        try {
            match ($decision) {
                'approve' => $corrections->approve($correction, $user, $validated['note'] ?? null),
                'reject' => $corrections->reject($correction, $user, (string) $validated['note']),
                'cancel' => $corrections->cancel($correction, $user),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('success', match ($decision) {
            'approve' => 'Koreksi disetujui. Stok penerima sudah disesuaikan; selisih baru (bila ada) menunggu diputuskan.',
            'reject' => 'Pengajuan koreksi ditolak. Catatan penerimaan tidak berubah.',
            'cancel' => 'Pengajuan koreksi dibatalkan.',
        });
    }

    /**
     * Batalkan transfer.
     * - Draft: status saja, tanpa mutasi stok.
     * - Sent: kembalikan stok sumber via mutasi pembalik + alasan wajib.
     */
    public function cancel(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        // Draft: dibatalkan oleh anggota sisi manapun.
        // Sent: dibatalkan oleh anggota sisi asal atau tujuan.
        if (! $this->canAccessTransfer($user, $stockTransfer)) {
            abort(403, 'Anda tidak punya akses ke transfer ini.');
        }

        // Pengelola di toko asal atau tujuan yang boleh membatalkan.
        if (! $this->canOperateAt($user, (int) $stockTransfer->from_store_id)
            && ! $this->canOperateAt($user, (int) $stockTransfer->to_store_id)) {
            abort(403, 'Hanya owner, admin, manajer, atau admin gudang toko terkait yang dapat membatalkan transfer.');
        }

        try {
            DB::transaction(function () use ($user, $stockTransfer, $validated) {
                // Kunci baris transfer sungguhan (status dibaca ulang) supaya aksi ganda tidak memotong stok dua kali.
                $stockTransfer = StockTransfer::query()->whereKey($stockTransfer->id)->lockForUpdate()->firstOrFail();
                $stockTransfer->assertCanTransitionTo(StockTransfer::STATUS_CANCELLED);

                if ($stockTransfer->isDraft()) {
                    // Draft: tidak ada mutasi stok, cukup ubah status.
                    $stockTransfer->update([
                        'status' => StockTransfer::STATUS_CANCELLED,
                        'cancelled_at' => now(),
                        'cancelled_by' => $user->id,
                        'cancellation_reason' => $validated['cancellation_reason'],
                    ]);
                    StockRequestWorkflow::syncFromTransfer($stockTransfer);

                    return;
                }

                // Sent: kembalikan stok sumber via mutasi pembalik.
                $fromStore = $stockTransfer->fromStore;
                $lines = $stockTransfer->lines()->with('fromProduct')->get();

                // Kunci produk sumber.
                $fromIds = $lines->pluck('from_product_id')->sort()->values();
                Product::query()->whereIn('id', $fromIds)->orderBy('id')->lockForUpdate()->get();

                foreach ($lines as $line) {
                    $product = $line->fromProduct;
                    $product->refresh();
                    TransferStockOperations::restoreAtSource($fromStore, $user, $product, (string) $line->quantity, $line);
                }

                $stockTransfer->update([
                    'status' => StockTransfer::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                    'cancellation_reason' => $validated['cancellation_reason'],
                ]);

                // F6: permintaan stok asal transfer ini ikut Dibatalkan.
                StockRequestWorkflow::syncFromTransfer($stockTransfer);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        $label = $stockTransfer->isSent() ? 'dikirim' : 'draft';

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', "Transfer {$label} berhasil dibatalkan.");
    }

    // ── Helpers ──

    /**
     * @return \Illuminate\Support\Collection<int, Store>
     */
    private function destinationStoresForUser(User $user, Store $current): \Illuminate\Support\Collection
    {
        if ($current->organization_id === null) {
            return collect();
        }

        $orgId = (int) $current->organization_id;

        if ($user->isSuperAdmin() || $user->isOwner()) {
            return Store::query()
                ->where('organization_id', $orgId)
                ->where('id', '!=', $current->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        // Admin, manager, warehouse: akses semua toko aktif dalam grup yang sama.
        // Ini memastikan admin gudang bisa transfer ke outlet meskipun tidak terdaftar langsung di pivot store_user.
        if ($user->isAdmin() || $user->isManager() || $user->isWarehouseStaff()) {
            return Store::query()
                ->where('organization_id', $orgId)
                ->where('id', '!=', $current->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        // Staff biasa (kasir): hanya toko yang punya akses.
        return $user->stores()
            ->where('stores.organization_id', $orgId)
            ->where('stores.id', '!=', $current->id)
            ->where('stores.is_active', true)
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name']);
    }

    /**
     * Check if a user can transfer to a store based on role + org membership.
     * Used when isMemberOfStore() fails (user not in pivot) but role grants org-wide access.
     */
    private function canTransferToStore(User $user, Store $fromStore, Store $toStore): bool
    {
        if ($user->isSuperAdmin() || $user->isOwner()) {
            return true;
        }

        if (! $toStore->is_active) {
            return false;
        }

        if (! $this->storesShareOrganization($fromStore, $toStore)) {
            return false;
        }

        return $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff();
    }

    /**
     * Akses ke transfer yang sudah ada. Anggota toko di sisi yang relevan selalu boleh.
     * Selain itu hanya owner/admin/manajer/staf gudang yang toko aktifnya berada
     * dalam grup usaha yang sama dengan KEDUA toko transfer — bukan admin toko mana pun.
     *
     * @param  'from'|'to'|'any'  $side
     */
    private function canAccessTransfer(User $user, StockTransfer $transfer, string $side = 'any'): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $isFromMember = $user->isMemberOfStore($transfer->from_store_id);
        $isToMember = $user->isMemberOfStore($transfer->to_store_id);
        if (match ($side) {
            'from' => $isFromMember,
            'to' => $isToMember,
            default => $isFromMember || $isToMember,
        }) {
            return true;
        }

        $active = $user->store;
        $from = $transfer->fromStore()->first(['id', 'organization_id']);
        $to = $transfer->toStore()->first(['id', 'organization_id']);
        if (! $active || ! $from || ! $to
            || ! $this->storesShareOrganization($active, $from)
            || ! $this->storesShareOrganization($active, $to)) {
            return false;
        }

        return $user->isOwner() || $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff();
    }

    /** Peran yang boleh mengirim / menerima / membatalkan transfer di sebuah toko. */
    private const OPERATOR_ROLES = ['owner', 'admin', 'manager', 'warehouse'];

    /**
     * Boleh menjalankan transfer di toko tertentu: peran di TOKO ITU (bukan toko yang
     * sedang aktif) harus owner/admin/manajer/admin gudang. Owner grup usaha juga boleh
     * untuk outlet dalam grupnya walau tidak terdaftar sebagai anggota outlet tersebut.
     */
    private function canOperateAt(User $user, int $storeId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $user->stores()->where('stores.id', $storeId)->first()?->pivot?->role;
        if ($role === null && (int) $user->store_id === $storeId) {
            $role = $user->role;
        }
        if (in_array($role, self::OPERATOR_ROLES, true)) {
            return true;
        }

        $organizationId = Store::query()->whereKey($storeId)->value('organization_id');

        return $organizationId !== null && $user->stores()
            ->wherePivot('role', 'owner')
            ->where('stores.organization_id', $organizationId)
            ->exists();
    }

    /** Fase F1: stok hanya mengalir dalam satu `organization_id`. */
    private function storesShareOrganization(Store $a, Store $b): bool
    {
        if ($a->organization_id === null || $b->organization_id === null) {
            return false;
        }

        return (int) $a->organization_id === (int) $b->organization_id;
    }

    private function findDestinationProduct(Store $toStore, Product $from): ?Product
    {
        $base = Product::query()
            ->where('store_id', $toStore->id)
            ->where('track_stock', true)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->where('is_active', true);

        $sku = trim((string) $from->sku);
        if ($sku !== '') {
            $hit = (clone $base)->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower($sku)])->first();
            if ($hit) {
                return $hit;
            }
        }

        $bc = trim((string) $from->barcode);
        if ($bc !== '') {
            return (clone $base)->where('barcode', $bc)->first();
        }

        return null;
    }
}
