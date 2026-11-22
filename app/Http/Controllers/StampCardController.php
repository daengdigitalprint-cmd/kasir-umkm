<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Services\ActivityLogger;
use App\Services\StampCardService;
use App\Support\ReceiptSettings;
use App\Support\StampCardSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu Stempel Digital: pengaturan & daftar (pengelola), cari/tukar di kasir,
 * dan halaman kartu publik untuk pelanggan (/k/{token}).
 */
class StampCardController extends Controller
{
    public function __construct(private StampCardService $service) {}

    private function store(): Store
    {
        $store = auth()->user()?->store;
        abort_unless($store, 404);

        return $store;
    }

    private function ownCustomer(Store $store, Customer $customer): void
    {
        abort_unless((int) $customer->store_id === (int) $store->id, 404);
    }

    // ───────────────────────── Pengelola ─────────────────────────

    public function index(Request $request): Response
    {
        $store = $this->store();
        $rules = StampCardSettings::all($store);
        $target = $rules['target'];
        $filter = in_array($request->input('filter'), ['ready', 'near', 'all'], true) ? $request->input('filter') : 'all';
        $search = trim((string) $request->input('q', ''));

        $base = Customer::query()->where('store_id', $store->id)
            ->where(fn ($q) => $q->where('stamp_count', '>', 0)->orWhere('stamp_rewards_ready', '>', 0)->orWhereNotNull('stamp_last_at'));

        $nearMin = max(1, $target - 2);
        $stats = [
            'members' => (clone $base)->count(),
            'ready' => (clone $base)->where('stamp_rewards_ready', '>', 0)->count(),
            'near' => (clone $base)->where('stamp_count', '>=', $nearMin)->count(),
            'redeemed' => (int) Customer::query()->where('store_id', $store->id)->sum('stamp_rewards_redeemed'),
        ];

        $customers = (clone $base)
            ->when($filter === 'ready', fn ($q) => $q->where('stamp_rewards_ready', '>', 0))
            ->when($filter === 'near', fn ($q) => $q->where('stamp_count', '>=', $nearMin))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderByDesc('stamp_rewards_ready')
            ->orderByDesc('stamp_count')
            ->orderByDesc('stamp_last_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $c) => $this->service->cardPayload($store, $c) + [
                'last_at' => $c->stamp_last_at?->toIso8601String(),
                'expired' => $this->service->isExpired($store, $c),
            ]);

        return Inertia::render('StampCard/Index', [
            'enabled' => StampCardSettings::enabled($store),
            'rules' => $rules,
            'stats' => $stats,
            'customers' => $customers,
            'filter' => $filter,
            'search' => $search,
            'store_name' => $store->name,
            'products' => Product::query()->where('store_id', $store->id)->where('is_active', true)
                ->orderBy('name')->limit(500)->get(['id', 'name']),
            'categories' => ProductCategory::query()->where('store_id', $store->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $store = $this->store();
        $data = $request->validate([
            'title' => 'required|string|max:60',
            'target' => 'required|integer|min:2|max:30',
            'mode' => 'required|in:transaction,item',
            'min_amount' => 'nullable|integer|min:0|max:100000000',
            'product_ids' => 'array',
            'product_ids.*' => 'integer',
            'category_ids' => 'array',
            'category_ids.*' => 'integer',
            'reward' => 'required|string|max:120',
            'expiry_months' => 'required|integer|min:0|max:36',
        ]);

        if ($data['mode'] === 'item' && empty($data['product_ids']) && empty($data['category_ids'])) {
            return back()->withErrors(['product_ids' => 'Pilih minimal satu produk atau kategori yang diberi stempel.']);
        }

        // Hanya simpan produk/kategori milik toko ini.
        $data['product_ids'] = Product::query()->where('store_id', $store->id)->whereIn('id', $data['product_ids'] ?? [])->pluck('id')->all();
        $data['category_ids'] = ProductCategory::query()->where('store_id', $store->id)->whereIn('id', $data['category_ids'] ?? [])->pluck('id')->all();
        $data['min_amount'] = (int) ($data['min_amount'] ?? 0);

        StampCardSettings::update($store, $data);

        ActivityLogger::log(store: $store, action: 'stamp_card.settings_updated', description: 'Mengubah aturan kartu stempel', metadata: StampCardSettings::all($store));

        return back()->with('success', 'Aturan kartu stempel disimpan.');
    }

    public function adjust(Request $request, Customer $customer): RedirectResponse
    {
        $store = $this->store();
        $this->ownCustomer($store, $customer);
        $data = $request->validate([
            'delta' => 'required|integer|min:-100|max:100|not_in:0',
            'note' => 'required|string|max:200',
        ]);

        $this->service->adjust($store, $customer, (int) $data['delta'], 'Koreksi: '.$data['note'], auth()->id());

        ActivityLogger::log(store: $store, action: 'stamp_card.adjusted', description: "Koreksi stempel {$customer->name} ({$data['delta']})", subject: $customer, metadata: $data);

        return back()->with('success', 'Stempel '.$customer->name.' dikoreksi.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $store = $this->store();
        $this->ownCustomer($store, $customer);

        $before = ['stamps' => (int) $customer->stamp_count, 'rewards_ready' => (int) $customer->stamp_rewards_ready];
        $this->service->removeCard($store, $customer, auth()->id());

        ActivityLogger::log(store: $store, action: 'stamp_card.removed', description: "Menghapus kartu stempel {$customer->name}", subject: $customer, metadata: $before);

        return back()->with('success', 'Kartu stempel '.$customer->name.' dihapus.');
    }

    public function history(Customer $customer): JsonResponse
    {
        $store = $this->store();
        $this->ownCustomer($store, $customer);

        $rows = $customer->stampLedgers()->with('creator:id,name')->latest('id')->limit(50)->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'type' => $l->type,
                'stamps' => $l->stamps,
                'rewards_delta' => $l->rewards_delta,
                'count_after' => $l->count_after,
                'rewards_after' => $l->rewards_after,
                'note' => $l->note,
                'by' => $l->creator?->name,
                'at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['history' => $rows, 'card' => $this->service->cardPayload($store, $customer)]);
    }

    // ───────────────────────── Kasir ─────────────────────────

    /** Cari kartu dari isi QR atau nomor WA. */
    public function lookup(Request $request): JsonResponse
    {
        $store = $this->store();
        if (! StampCardSettings::enabled($store)) {
            return response()->json(['enabled' => false, 'customer' => null]);
        }

        $customer = $this->service->findCustomer($store, (string) $request->input('q', ''));

        return response()->json([
            'enabled' => true,
            'customer' => $customer ? $this->service->cardPayload($store, $customer) : null,
        ]);
    }

    /** Data kartu pelanggan yang dipilih kasir lewat pencarian/tambah cepat. */
    public function card(Customer $customer): JsonResponse
    {
        $store = $this->store();
        $this->ownCustomer($store, $customer);
        abort_unless(StampCardSettings::enabled($store), 404);

        return response()->json(['customer' => $this->service->cardPayload($store, $customer)]);
    }

    public function redeem(Customer $customer): JsonResponse
    {
        $store = $this->store();
        $this->ownCustomer($store, $customer);
        abort_unless(StampCardSettings::enabled($store), 404);

        try {
            $summary = $this->service->redeem($store, $customer, auth()->id());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLogger::log(store: $store, action: 'stamp_card.redeemed', description: "Tukar hadiah kartu stempel {$customer->name}", subject: $customer);

        return response()->json([
            'message' => 'Hadiah ditukar: '.$summary['reward'],
            'customer' => $this->service->cardPayload($store, $customer->refresh()),
        ]);
    }

    // ───────────────────────── Publik ─────────────────────────

    public function publicShow(string $token): Response
    {
        $customer = Customer::query()->where('stamp_card_token', strtolower($token))->first();
        abort_unless($customer, 404);
        $store = Store::query()->find($customer->store_id);
        abort_unless($store, 404);

        $receipt = ReceiptSettings::payload($store);
        $enabled = StampCardSettings::enabled($store) && (bool) $store->is_active;
        $rules = StampCardSettings::all($store);

        return Inertia::render('StampCard/Public', [
            'enabled' => $enabled,
            'store' => [
                'name' => $receipt['header_name'] ?? $store->name,
                'logo_url' => $receipt['logo_url'] ?? null,
            ],
            'card' => $enabled ? [
                'title' => $rules['title'],
                'first_name' => $this->greetingName($customer->name),
                'count' => $this->service->currentCount($store, $customer),
                'target' => $rules['target'],
                'rewards_ready' => (int) $customer->stamp_rewards_ready,
                'reward' => $rules['reward'],
                'rule_text' => $this->ruleText($rules),
                'expiry_months' => $rules['expiry_months'],
                'url' => url('/k/'.$customer->stamp_card_token),
                'history' => $customer->stampLedgers()->whereIn('type', ['earn', 'redeem', 'adjust', 'reverse', 'expire'])
                    ->latest('id')->limit(5)->get(['type', 'stamps', 'rewards_delta', 'created_at'])
                    ->map(fn ($l) => ['type' => $l->type, 'stamps' => $l->stamps, 'rewards_delta' => $l->rewards_delta, 'at' => $l->created_at?->toIso8601String()]),
            ] : null,
        ]);
    }

    /** Nama sapaan: kata pertama, atau dua kata bila diawali gelar (Pak Budi, Bu Rina). */
    private function greetingName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $titles = ['pak', 'bu', 'ibu', 'bapak', 'mas', 'mbak', 'kak', 'bang', 'dik', 'om', 'tante'];
        if (count($words) >= 2 && in_array(mb_strtolower(rtrim($words[0], '.')), $titles, true)) {
            return $words[0].' '.$words[1];
        }

        return $words[0] ?? '';
    }

    /** @param  array<string, mixed>  $rules */
    private function ruleText(array $rules): string
    {
        if ($rules['mode'] === 'item') {
            return '1 stempel untuk setiap menu pilihan yang dibeli.';
        }

        return $rules['min_amount'] > 0
            ? '1 stempel setiap belanja minimal Rp'.number_format($rules['min_amount'], 0, ',', '.').'.'
            : '1 stempel setiap kali belanja.';
    }
}
