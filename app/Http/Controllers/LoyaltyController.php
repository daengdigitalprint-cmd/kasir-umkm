<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyNotification;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Store;
use App\Services\LoyaltyService;
use App\Support\LoyaltySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** Panel admin: ringkasan program loyalitas. */
    public function index()
    {
        $store = $this->currentStore();

        $totalCirculating = (int) $store->customers()->sum('points_balance');
        $totalEarned = (int) $store->customers()->sum('points_earned_total');
        $totalRedeemed = (int) $store->customers()->sum('points_redeemed_total');
        $membersWithPoints = (int) $store->customers()->where('points_balance', '>', 0)->count();

        $rewards = $store->loyaltyRewards()->latest()->get();

        $recentRedemptions = $store->loyaltyRedemptions()
            ->with('customer:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (LoyaltyRedemption $r) => [
                'id' => $r->id,
                'customer_name' => $r->customer?->name ?? '-',
                'reward_name' => $r->reward_name,
                'reward_type' => $r->reward_type,
                'points_spent' => $r->points_spent,
                'voucher_value' => $r->voucher_value,
                'voucher_code' => $r->voucher_code,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $topMembers = $store->customers()
            ->where('points_balance', '>', 0)
            ->orderByDesc('points_balance')
            ->limit(10)
            ->get(['id', 'name', 'points_balance', 'points_earned_total', 'points_redeemed_total']);

        return Inertia::render('Loyalty/Index', [
            'settings' => LoyaltySettings::all($store),
            'stats' => [
                'total_circulating' => $totalCirculating,
                'total_earned' => $totalEarned,
                'total_redeemed' => $totalRedeemed,
                'members_with_points' => $membersWithPoints,
                'rewards_count' => $rewards->count(),
            ],
            'rewards' => $rewards,
            'recentRedemptions' => $recentRedemptions,
            'topMembers' => $topMembers,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    /** Simpan aturan perolehan / kadaluarsa poin. */
    public function updateSettings(Request $request)
    {
        $store = $this->currentStore();

        $validated = $request->validate([
            'enabled' => 'boolean',
            'rupiah_per_point' => 'required|integer|min:1|max:10000000',
            'birthday_bonus_points' => 'required|integer|min:0|max:1000000',
            'referral_bonus_points' => 'required|integer|min:0|max:1000000',
            'expiry_months' => 'required|integer|min:0|max:120',
            'expiry_warn_days' => 'required|integer|min:1|max:365',
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        LoyaltySettings::update($store, $validated);

        Log::info('[loyalty] aturan diperbarui', [
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'values' => $validated,
        ]);

        return back()->with('success', 'Aturan program loyalitas disimpan.');
    }

    /** Tambah hadiah baru ke katalog. */
    public function storeReward(Request $request)
    {
        $store = $this->currentStore();

        $validated = $this->validateReward($request);

        $store->loyaltyRewards()->create($validated);

        return back()->with('success', 'Hadiah ditambahkan.');
    }

    /** Perbarui hadiah. */
    public function updateReward(Request $request, LoyaltyReward $reward)
    {
        $this->authorizeReward($reward);

        $validated = $this->validateReward($request);

        $reward->update($validated);

        return back()->with('success', 'Hadiah diperbarui.');
    }

    /** Hapus hadiah. */
    public function destroyReward(LoyaltyReward $reward)
    {
        $this->authorizeReward($reward);

        $reward->delete();

        return back()->with('success', 'Hadiah dihapus.');
    }

    /** Aktif/nonaktifkan hadiah tanpa mengirim ulang seluruh field. */
    public function toggleReward(LoyaltyReward $reward)
    {
        $this->authorizeReward($reward);

        $reward->update(['is_active' => ! $reward->is_active]);

        return back()->with('success', $reward->is_active ? 'Hadiah diaktifkan.' : 'Hadiah dinonaktifkan.');
    }

    /** Validasi kode voucher loyalitas dari POS (tanpa mengubah status). */
    public function previewVoucher(Request $request)
    {
        $store = $this->currentStore();

        $validated = $request->validate([
            'code' => 'required|string|max:40',
        ]);

        try {
            $redemption = $this->loyalty->previewVoucher($store, $validated['code']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'voucher' => [
                'code' => $redemption->voucher_code,
                'value' => (float) $redemption->voucher_value,
                'reward_name' => $redemption->reward_name,
                'customer_id' => $redemption->customer_id,
                'customer_name' => $redemption->customer?->name,
            ],
        ]);
    }

    /** Proses penukaran hadiah untuk seorang pelanggan (dilakukan staf). */
    public function redeem(Request $request)
    {
        $store = $this->currentStore();

        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'reward_id' => 'required|integer',
        ]);

        $customer = $store->customers()->findOrFail($validated['customer_id']);
        $reward = $store->loyaltyRewards()->findOrFail($validated['reward_id']);

        try {
            $this->loyalty->redeem($customer, $reward, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['redeem' => $e->getMessage()]);
        }

        return back()->with('success', 'Penukaran hadiah berhasil.');
    }

    /** Penyesuaian manual / klaim poin oleh admin. */
    public function adjust(Request $request)
    {
        $store = $this->currentStore();

        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'delta' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
        ]);

        $customer = $store->customers()->findOrFail($validated['customer_id']);

        try {
            $this->loyalty->adjust($customer, (int) $validated['delta'], $validated['reason'], auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['adjust' => $e->getMessage()]);
        }

        Log::info('[loyalty] penyesuaian poin oleh admin', [
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'delta' => $validated['delta'],
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'Saldo poin pelanggan disesuaikan.');
    }

    /** Kirim notifikasi promo poin ke pelanggan (in-app + log). */
    public function broadcastPromo(Request $request)
    {
        $store = $this->currentStore();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:1000',
        ]);

        // Kirim asinkron agar request admin tidak menunggu ratusan/ribuan insert notifikasi.
        \App\Jobs\BroadcastLoyaltyPromo::dispatch($store->id, $validated['title'], $validated['body']);

        return back()->with('success', 'Promo sedang dikirim ke seluruh pelanggan.');
    }

    /** Inbox notifikasi loyalitas in-app untuk staf toko. */
    public function notifications()
    {
        $store = $this->currentStore();

        $notifications = $store->loyaltyNotifications()
            ->with('customer:id,name')
            ->latest()
            ->paginate(30)
            ->through(fn (LoyaltyNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'channel' => $n->channel,
                'title' => $n->title,
                'body' => $n->body,
                'customer_name' => $n->customer?->name,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Loyalty/Notifications', [
            'notifications' => $notifications,
            'unreadCount' => $store->loyaltyNotifications()->whereNull('read_at')->count(),
        ]);
    }

    /** Tandai notifikasi loyalitas sebagai telah dibaca. */
    public function markNotificationRead(LoyaltyNotification $notification)
    {
        if ((int) $notification->store_id !== (int) $this->currentStore()->id) {
            abort(403);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back();
    }

    private function currentStore(): Store
    {
        // Gerbang plan & toko sudah ditangani middleware plan.advanced + store.active.
        $store = auth()->user()->store;

        if (! $store) {
            abort(404);
        }

        return $store;
    }

    private function authorizeReward(LoyaltyReward $reward): void
    {
        $store = auth()->user()->store;

        if ($store === null || (int) $reward->store_id !== (int) $store->id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReward(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:voucher,free_product,service',
            'points_cost' => 'required|integer|min:1|max:100000000',
            'voucher_value' => 'nullable|numeric|min:0|max:1000000000',
            'stock' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'boolean',
        ]);

        if ($validated['type'] === 'voucher' && empty($validated['voucher_value'])) {
            throw ValidationException::withMessages([
                'voucher_value' => 'Nilai voucher wajib diisi untuk hadiah tipe voucher.',
            ]);
        }

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['voucher_value'] = $validated['type'] === 'voucher' ? $validated['voucher_value'] : null;

        return $validated;
    }
}
