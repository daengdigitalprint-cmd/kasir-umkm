<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use App\Support\TransferStockOperations;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
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
            // F5: jumlah barang yang diterima kurang dari yang dikirim.
            ->withCount(['lines as discrepancy_lines_count' => fn ($q) => $q->whereNotNull('quantity_received')
                ->whereColumn('quantity_received', '<', 'quantity')]);

        if ($request->boolean('discrepancy')) {
            $query->whereHas('lines', fn ($q) => $q->whereNotNull('quantity_received')
                ->whereColumn('quantity_received', '<', 'quantity'));
        }

        if ($request->filled('status') && in_array($request->input('status'), ['draft', 'sent', 'received', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        $transfers = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'currentStore' => ['id' => $store->id, 'name' => $store->name],
            'canCreateTransfer' => StorePlanLimits::allowsAdvancedRetailFeatures($store),
            'statusFilter' => $request->input('status'),
            'discrepancyFilter' => $request->boolean('discrepancy'),
        ]);
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

        return Inertia::render('StockTransfers/Create', [
            'sourceStore' => [
                'id' => $store->id,
                'name' => $store->name,
                'organization_name' => $store->organization?->name,
            ],
            'destStores' => $destStores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'products' => $products,
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
        ]);

        $canSend = $stockTransfer->isDraft()
            && $user->isMemberOfStore($stockTransfer->from_store_id)
            && ($user->isAdmin() || $user->isManager());

        $canReceive = $stockTransfer->isSent()
            && $user->isMemberOfStore($stockTransfer->to_store_id)
            && ($user->isAdmin() || $user->isManager());

        $canCancel = ($stockTransfer->isDraft() || $stockTransfer->isSent())
            && ($user->isAdmin() || $user->isManager());

        return Inertia::render('StockTransfers/Show', [
            'transfer' => $stockTransfer,
            'canSend' => $canSend,
            'canReceive' => $canReceive,
            'canCancel' => $canCancel,
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

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'transfer' => $stockTransfer,
            'statusLabel' => $stockTransfer->isSent() ? 'Dikirim' : 'Diterima',
            'timezone' => $stockTransfer->fromStore?->timezone ?: config('app.timezone'),
        ])->setPaper('a4');

        return $pdf->download('surat-jalan-'.$stockTransfer->transfer_number.'.pdf');
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

        // Hanya admin atau manager yang dapat mengirim transfer
        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manager yang dapat mengirim transfer.');
        }

        try {
            DB::transaction(function () use ($user, $stockTransfer) {
                $stockTransfer->lockForUpdate();
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

        // Hanya admin atau manager yang dapat menerima transfer
        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manager yang dapat menerima transfer.');
        }

        // F5: jumlah diterima per baris (opsional). Tanpa isian = diterima penuh (perilaku lama).
        $validated = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.*.quantity_received.min' => 'Jumlah diterima tidak boleh negatif.',
        ]);
        $receivedInput = collect($validated['lines'] ?? [])->keyBy(fn ($row) => (int) $row['id']);

        $discrepancyCount = 0;

        try {
            DB::transaction(function () use ($user, $stockTransfer, $receivedInput, &$discrepancyCount) {
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
                        $qty = ProductQuantity::normalizeStorageAmount($product, $input['quantity_received']);
                        if (ProductQuantity::cmp($qty, $sentQty) > 0) {
                            throw ValidationException::withMessages([
                                'lines' => "Jumlah diterima «{$product->name}» melebihi jumlah yang dikirim.",
                            ]);
                        }

                        $reason = trim((string) ($input['discrepancy_reason'] ?? ''));
                        $isShort = ProductQuantity::cmp($qty, $sentQty) < 0;
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

                        $line->update([
                            'quantity_received' => $qty,
                            'discrepancy_reason' => $isShort ? mb_substr($reason, 0, 255) : null,
                        ]);

                        if ($isShort) {
                            $discrepancyCount++;
                            $short = ProductQuantity::sub($sentQty, $qty);
                            $notes = mb_substr(
                                "{$stockTransfer->transfer_number} · diterima {$qty} dari {$sentQty}, selisih {$short}: {$reason}",
                                0,
                                255,
                            );
                        }
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
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', $discrepancyCount > 0
                ? "Transfer diterima dengan selisih pada {$discrepancyCount} barang. Stok bertambah sesuai jumlah yang benar-benar tiba."
                : 'Transfer berhasil diterima. Stok outlet telah bertambah.');
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

        // Hanya admin atau manager yang dapat membatalkan transfer
        if (! $user->isAdmin() && ! $user->isManager()) {
            abort(403, 'Hanya admin atau manager yang dapat membatalkan transfer.');
        }

        try {
            DB::transaction(function () use ($user, $stockTransfer, $validated) {
                $stockTransfer->lockForUpdate();
                $stockTransfer->assertCanTransitionTo(StockTransfer::STATUS_CANCELLED);

                if ($stockTransfer->isDraft()) {
                    // Draft: tidak ada mutasi stok, cukup ubah status.
                    $stockTransfer->update([
                        'status' => StockTransfer::STATUS_CANCELLED,
                        'cancelled_at' => now(),
                        'cancelled_by' => $user->id,
                        'cancellation_reason' => $validated['cancellation_reason'],
                    ]);

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
