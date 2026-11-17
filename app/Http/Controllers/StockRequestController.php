<?php

namespace App\Http\Controllers;

use App\Actions\StockRequestWorkflow;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\AutoStockRequest;
use App\Support\CentralKitchenModule;
use App\Support\StoreLocationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F6 — Permintaan Stok antar lokasi (Gudang Pusat Pro).
 * Outlet meminta → Gudang Pusat merevisi & menyetujui → Transfer Stok terkirim.
 */
class StockRequestController extends Controller
{
    public function __construct(private readonly StockRequestWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $store = $request->user()->store;
        $defaultTab = StoreLocationType::isCentralWarehouse($store) ? 'incoming' : 'outgoing';
        $tab = in_array($request->input('tab'), ['incoming', 'outgoing'], true) ? $request->input('tab') : $defaultTab;

        $requests = StockRequest::query()
            ->where($tab === 'incoming' ? 'to_store_id' : 'from_store_id', $store->id)
            ->with(['fromStore:id,name', 'toStore:id,name', 'requester:id,name', 'transfer:id,transfer_number,status'])
            ->withCount('lines')
            // Yang menunggu tindakan di atas, lalu yang terbaru.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StockRequest::STATUS_REQUESTED])
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockRequest $r) => [
                'id' => $r->id,
                'request_number' => $r->request_number,
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'from_store' => $r->fromStore?->only(['id', 'name']),
                'to_store' => $r->toStore?->only(['id', 'name']),
                'requester' => $r->is_automatic ? 'Otomatis' : $r->requester?->name,
                'is_automatic' => $r->is_automatic,
                'lines_count' => $r->lines_count,
                'transfer' => $r->transfer?->only(['id', 'transfer_number', 'status']),
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('StockRequests/Index', [
            'requests' => $requests,
            'tab' => $tab,
            'store' => ['id' => $store->id, 'name' => $store->name, 'is_central_warehouse' => StoreLocationType::isCentralWarehouse($store)],
            'pendingIncoming' => StockRequest::query()
                ->where('to_store_id', $store->id)
                ->where('status', StockRequest::STATUS_REQUESTED)
                ->count(),
            'canCreate' => CentralKitchenModule::siblingStores($store)->isNotEmpty(),
            'autoRequest' => $this->autoRequestPayload($request->user(), $store),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        $suppliers = CentralKitchenModule::siblingStores($store);
        if ($suppliers->isEmpty()) {
            return redirect()->route('stock-requests.index')
                ->with('warning', 'Belum ada lokasi lain dalam grup usaha ini untuk dimintai stok.');
        }

        $products = $this->transferableProducts($store);

        // Stok pemasok untuk tiap barang peminta (null = pemasok tidak punya padanannya).
        $availability = [];
        foreach ($suppliers as $supplier) {
            $index = $this->matchIndex($supplier);
            foreach ($products as $product) {
                $match = $this->lookup($index, $product);
                $availability[$supplier->id][$product->id] = $match ? (float) $match->stock_quantity : null;
            }
        }

        return Inertia::render('StockRequests/Create', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'suppliers' => $suppliers->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_central_warehouse' => StoreLocationType::isCentralWarehouse($s),
            ])->values(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'unit' => $p->baseUnit(),
                'allows_decimal' => (bool) $p->allows_decimal,
                'stock' => (float) $p->stock_quantity,
                'min_stock' => (float) $p->min_stock_level,
            ])->values(),
            'availability' => $availability,
            'prefill' => $this->prefill($request, $products),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'to_store_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [
            'items.required' => 'Tambahkan minimal satu barang.',
            'items.*.quantity.gt' => 'Jumlah harus lebih dari 0.',
        ]);

        $supplier = Store::query()->find($validated['to_store_id']);
        if (! $supplier) {
            return back()->withErrors(['to_store_id' => 'Lokasi pemasok tidak ditemukan.']);
        }

        $stockRequest = $this->workflow->create($user, $user->store, $supplier, $validated['items'], $validated['notes'] ?? null);

        return redirect()->route('stock-requests.show', $stockRequest)
            ->with('success', "Permintaan {$stockRequest->request_number} terkirim ke {$supplier->name}.");
    }

    public function show(Request $request, StockRequest $stockRequest): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user, $stockRequest), 403);

        $stockRequest->load([
            'fromStore:id,name',
            'toStore:id,name',
            'requester:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'transfer:id,transfer_number,status',
            'lines.requesterProduct:id,name,sku,base_unit,allows_decimal,stock_quantity',
            'lines.supplierProduct:id,name,sku,base_unit,allows_decimal,stock_quantity',
        ]);

        return Inertia::render('StockRequests/Show', [
            'request' => [
                'id' => $stockRequest->id,
                'request_number' => $stockRequest->request_number,
                'status' => $stockRequest->status,
                'status_label' => $stockRequest->statusLabel(),
                'notes' => $stockRequest->notes,
                'from_store' => $stockRequest->fromStore?->only(['id', 'name']),
                'to_store' => $stockRequest->toStore?->only(['id', 'name']),
                'requester' => $stockRequest->is_automatic ? 'Otomatis (jadwal harian)' : $stockRequest->requester?->name,
                'is_automatic' => $stockRequest->is_automatic,
                'approver' => $stockRequest->approver?->name,
                'rejecter' => $stockRequest->rejecter?->name,
                'rejected_reason' => $stockRequest->rejected_reason,
                'created_at' => $stockRequest->created_at?->toIso8601String(),
                'approved_at' => $stockRequest->approved_at?->toIso8601String(),
                'rejected_at' => $stockRequest->rejected_at?->toIso8601String(),
                'cancelled_at' => $stockRequest->cancelled_at?->toIso8601String(),
                'completed_at' => $stockRequest->completed_at?->toIso8601String(),
                'transfer' => $stockRequest->transfer?->only(['id', 'transfer_number', 'status']),
                'lines' => $stockRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'name' => $line->requesterProduct?->name ?? $line->supplierProduct?->name,
                    'sku' => $line->requesterProduct?->sku,
                    'unit' => $line->base_unit,
                    'allows_decimal' => (bool) ($line->supplierProduct?->allows_decimal ?? $line->requesterProduct?->allows_decimal),
                    'quantity_requested' => (float) $line->quantity_requested,
                    'quantity_approved' => $line->quantity_approved === null ? null : (float) $line->quantity_approved,
                    'stock_at_request' => (float) $line->stock_at_request,
                    'min_stock_at_request' => (float) $line->min_stock_at_request,
                    'supplier_stock' => $line->supplierProduct ? (float) $line->supplierProduct->stock_quantity : null,
                ])->values(),
            ],
            'canApprove' => $stockRequest->isRequested() && $this->canDecide($user, $stockRequest),
            'canCancel' => $stockRequest->isRequested() && $this->canCancel($user, $stockRequest),
        ]);
    }

    public function approve(Request $request, StockRequest $stockRequest): RedirectResponse
    {
        abort_unless($this->canDecide($request->user(), $stockRequest), 403);

        $validated = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity_approved' => ['required', 'numeric', 'min:0'],
        ]);
        $approved = collect($validated['lines'] ?? [])
            ->mapWithKeys(fn ($row) => [(int) $row['id'] => $row['quantity_approved']])
            ->all();

        $result = $this->workflow->approve($stockRequest, $request->user(), $approved);

        return redirect()->route('stock-requests.show', $result)
            ->with('success', 'Permintaan disetujui. Transfer Stok sudah dikirim ke '.$result->fromStore?->name.'.');
    }

    public function reject(Request $request, StockRequest $stockRequest): RedirectResponse
    {
        abort_unless($this->canDecide($request->user(), $stockRequest), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Tulis alasan penolakan.', 'reason.min' => 'Alasan minimal 3 karakter.']);

        $this->workflow->reject($stockRequest, $request->user(), $validated['reason']);

        return redirect()->route('stock-requests.show', $stockRequest)->with('success', 'Permintaan ditolak.');
    }

    public function cancel(Request $request, StockRequest $stockRequest): RedirectResponse
    {
        abort_unless($this->canCancel($request->user(), $stockRequest), 403);

        $this->workflow->cancel($stockRequest);

        return redirect()->route('stock-requests.show', $stockRequest)->with('success', 'Permintaan dibatalkan.');
    }

    // ── Order otomatis ke Gudang Pusat ──

    public function updateAutoSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        abort_unless($this->canManageAuto($user) && ! StoreLocationType::isCentralWarehouse($store), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'supplier_store_id' => ['nullable', 'integer'],
        ], ['time.date_format' => 'Format jam HH:MM, mis. 21:00.']);

        $supplierId = $validated['supplier_store_id'] ?? null;
        if ($supplierId !== null && ! CentralKitchenModule::siblingStores($store)->contains('id', (int) $supplierId)) {
            return back()->withErrors(['supplier_store_id' => 'Pilih lokasi dalam grup usaha yang sama.']);
        }

        $changes = [
            'enabled' => (bool) $validated['enabled'],
            'time' => $validated['time'],
            'supplier_store_id' => $supplierId !== null ? (int) $supplierId : null,
        ];
        // Jam diubah ke waktu yang belum lewat hari ini → izinkan jalan lagi hari ini.
        if ($changes['time'] !== AutoStockRequest::settings($store)['time']) {
            $changes['last_run_date'] = null;
        }
        AutoStockRequest::saveSettings($store, $changes);

        return back()->with('success', $changes['enabled']
            ? "Permintaan otomatis aktif setiap hari jam {$changes['time']}."
            : 'Permintaan otomatis dimatikan.');
    }

    /** Buat permintaan otomatis sekarang (uji coba / demo). */
    public function runAuto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        abort_unless($this->canManageAuto($user) && ! StoreLocationType::isCentralWarehouse($store), 403);

        $created = AutoStockRequest::run($store);

        return $created
            ? redirect()->route('stock-requests.show', $created)->with('success', "Permintaan otomatis {$created->request_number} terkirim ke {$created->toStore?->name}.")
            : back()->with('warning', 'Tidak ada barang yang perlu diminta: semua stok di atas Stok Minimum, sudah ada di permintaan yang masih terbuka, atau tidak dipunyai lokasi pemasok.');
    }

    private function canManageAuto(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOwner() || $user->isAdmin() || $user->isManager();
    }

    /** @return array<string, mixed>|null */
    private function autoRequestPayload(User $user, Store $store): ?array
    {
        if (StoreLocationType::isCentralWarehouse($store)) {
            return null;
        }

        $settings = AutoStockRequest::settings($store);
        $supplier = AutoStockRequest::supplierFor($store);

        return [
            'enabled' => $settings['enabled'],
            'time' => $settings['time'],
            'supplier_store_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'last_run_date' => $settings['last_run_date'],
            'pending_items' => $supplier ? count(AutoStockRequest::items($store, $supplier)) : 0,
            'timezone' => $store->timezone ?: config('app.timezone'),
            'can_manage' => $this->canManageAuto($user),
        ];
    }

    // ── Hak akses ──

    /** Anggota lokasi peminta/pemasok, atau pengelola lain dalam grup yang sama. */
    private function canView(User $user, StockRequest $stockRequest): bool
    {
        if ($user->isSuperAdmin()
            || $user->isMemberOfStore($stockRequest->from_store_id)
            || $user->isMemberOfStore($stockRequest->to_store_id)) {
            return true;
        }

        $active = $user->store;

        return $active !== null
            && (int) $active->organization_id === (int) $stockRequest->organization_id
            && ($user->isOwner() || $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff());
    }

    /** Setujui/tolak: dari lokasi pemasok yang sedang aktif, oleh pengelola stok. */
    private function canDecide(User $user, StockRequest $stockRequest): bool
    {
        $active = $user->store;
        if (! $active || (int) $active->id !== (int) $stockRequest->to_store_id) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isOwner() || $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff();
    }

    /** Batalkan: pembuat permintaan, atau pengelola di lokasi peminta yang sedang aktif. */
    private function canCancel(User $user, StockRequest $stockRequest): bool
    {
        $active = $user->store;
        if (! $active || (int) $active->id !== (int) $stockRequest->from_store_id) {
            return false;
        }

        return (int) $stockRequest->requested_by === (int) $user->id
            || $user->isSuperAdmin() || $user->isOwner() || $user->isAdmin() || $user->isManager() || $user->isWarehouseStaff();
    }

    // ── Bantuan data ──

    private function transferableProducts(Store $store)
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'base_unit', 'allows_decimal', 'stock_quantity', 'min_stock_level']);
    }

    /** Indeks produk pemasok per SKU (huruf kecil) dan barcode, untuk pencocokan cepat. */
    private function matchIndex(Store $supplier): array
    {
        $bySku = [];
        $byBarcode = [];
        foreach ($this->transferableProducts($supplier) as $product) {
            $sku = mb_strtolower(trim((string) $product->sku));
            if ($sku !== '') {
                $bySku[$sku] ??= $product;
            }
            $barcode = trim((string) $product->barcode);
            if ($barcode !== '') {
                $byBarcode[$barcode] ??= $product;
            }
        }

        return [$bySku, $byBarcode];
    }

    /** Sama dengan StockRequestWorkflow::matchingProduct, tanpa query per barang. */
    private function lookup(array $index, Product $product): ?Product
    {
        [$bySku, $byBarcode] = $index;
        $sku = mb_strtolower(trim((string) $product->sku));
        $match = $sku !== '' ? ($bySku[$sku] ?? null) : null;
        $barcode = trim((string) $product->barcode);
        if (! $match && $barcode !== '') {
            $match = $byBarcode[$barcode] ?? null;
        }

        return $match && $match->baseUnit() === $product->baseUnit() ? $match : null;
    }

    /**
     * Isi awal dari Daftar Belanja (?items[]=produk:varian:jumlah, format sama dengan Pembelian).
     *
     * @return list<array{product_id: int, quantity: float}>
     */
    private function prefill(Request $request, $products): array
    {
        $raw = $request->query('items');
        if (! is_array($raw)) {
            return [];
        }

        $ids = $products->pluck('id')->flip();
        $lines = [];
        foreach (array_slice($raw, 0, 200) as $item) {
            if (! is_string($item) || ! preg_match('/^(\d+):(\d*):(\d+(?:\.\d+)?)$/', $item, $m)) {
                continue;
            }
            if ($m[2] !== '' || ! $ids->has((int) $m[1]) || (float) $m[3] <= 0) {
                continue; // varian belum didukung transfer stok
            }
            $lines[] = ['product_id' => (int) $m[1], 'quantity' => (float) $m[3]];
        }

        return $lines;
    }
}
