<?php

namespace App\Modules\CashierPin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Modules\CashierPin\Services\CashierPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierSettingsController extends Controller
{
    public function __construct(
        private readonly CashierPinService $pinService,
    ) {}

    /** Ambil toko aktif pengguna; fallback ke toko pertama untuk admin/owner multi-outlet tanpa store_id. */
    private function store(Request $request): ?Store
    {
        $user = $request->user();

        return $user->store ?? $user->stores()->first();
    }

    private function ensureMember(Store $store, User $member): void
    {
        if (! $store->members()->whereKey($member->id)->exists()) {
            abort(403);
        }
    }

    public function index(Request $request): Response
    {
        $store = $this->store($request);

        if (! $store) {
            abort(404, 'Toko tidak ditemukan.');
        }

        $cashiers = $store->members()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.cashier_pin_hash', 'users.cashier_pin_set_at', 'users.can_login_pos'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'position' => $this->pinService->roleLabel($member->pivot->role),
                'has_pin' => $member->hasCashierPin(),
                'can_login_pos' => (bool) $member->can_login_pos,
                'pin_set_at' => $member->cashier_pin_set_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/CashierPin', [
            'cashier_login_mode' => $store->cashier_login_mode ?? 'email',
            'cashiers' => $cashiers,
        ]);
    }

    public function updateMode(Request $request): RedirectResponse
    {
        $store = $this->store($request);

        if (! $store) {
            abort(404);
        }

        $validated = $request->validate([
            'cashier_login_mode' => ['required', 'in:email,pin'],
        ]);

        $store->update(['cashier_login_mode' => $validated['cashier_login_mode']]);

        return back()->with('success', 'Mode login kasir diperbarui.');
    }

    public function setPin(Request $request, User $user): RedirectResponse
    {
        $store = $this->store($request);

        if (! $store) {
            abort(404);
        }

        $this->ensureMember($store, $user);

        $validated = $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:6', 'regex:/^[0-9]+$/'],
        ]);

        if (! $this->pinService->setPin($user, $validated['pin'])) {
            return back()->withErrors([
                'pin' => 'PIN tidak valid. Gunakan 4-6 digit angka. Hindari pola mudah (1234, 0000, dll).',
            ]);
        }

        return back()->with('success', "PIN berhasil diatur untuk {$user->name}.");
    }

    public function clearPin(Request $request, User $user): RedirectResponse
    {
        $store = $this->store($request);

        if (! $store) {
            abort(404);
        }

        $this->ensureMember($store, $user);

        $this->pinService->clearPin($user);

        return back()->with('success', "PIN dihapus untuk {$user->name}.");
    }

    public function togglePos(Request $request, User $user): RedirectResponse
    {
        $store = $this->store($request);

        if (! $store) {
            abort(404);
        }

        $this->ensureMember($store, $user);

        $user->update(['can_login_pos' => ! $user->can_login_pos]);

        $status = $user->can_login_pos ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', "Akses POS {$status} untuk {$user->name}.");
    }
}
