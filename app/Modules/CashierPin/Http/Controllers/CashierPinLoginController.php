<?php

namespace App\Modules\CashierPin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\CashierPin\Services\CashierPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierPinLoginController extends Controller
{
    public function __construct(
        private readonly CashierPinService $pinService,
        private readonly AttendanceService $attendanceService,
    ) {}

    private function store(Request $request): ?Store
    {
        $user = $request->user();

        return $user->store ?? $user->stores()->first();
    }

    public function show(Request $request): Response
    {
        $store = $this->store($request);

        if (! $store || $store->cashier_login_mode !== 'pin') {
            abort(403, 'Mode PIN tidak aktif untuk toko ini.');
        }

        $cashiers = $this->pinService->getCashiersForStore($store->id)
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'position' => $this->pinService->roleLabel($member->pivot->role ?? null),
                'has_pin' => $member->hasCashierPin(),
            ]);

        return Inertia::render('CashierPin/Login', [
            'cashiers' => $cashiers,
            'store_name' => $store->name,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $store = $this->store($request);

        if (! $store || $store->cashier_login_mode !== 'pin') {
            abort(403, 'Mode PIN tidak aktif.');
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'pin' => ['required', 'string', 'min:4', 'max:6'],
        ]);

        $cashier = User::query()
            ->where('id', $validated['employee_id'])
            ->where('is_active', true)
            ->where('can_login_pos', true)
            ->whereHas('stores', fn ($query) => $query->where('stores.id', $store->id))
            ->first();

        if (! $cashier || ! $this->pinService->verifyPin($cashier, $validated['pin'])) {
            return back()->withErrors([
                'pin' => 'PIN salah atau kasir tidak ditemukan.',
            ]);
        }

        $employee = $this->attendanceService->resolveEmployee($cashier, $store);

        $request->session()->put('active_cashier', [
            'employee_id' => $employee->id,
            'employee_name' => $cashier->name,
            'user_id' => $cashier->id,
            'logged_in_at' => now()->toIso8601String(),
        ]);

        return redirect($this->returnPath($request))
            ->with('success', "Selamat bertugas, {$cashier->name}!");
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('active_cashier');

        return redirect($this->returnPath($request))
            ->with('info', 'Kasir telah keluar.');
    }

    /**
     * Tujuan setelah login/keluar PIN: default POS, atau kembali ke halaman absensi
     * (perangkat bersama untuk absen bergantian). Hanya path internal yang diizinkan.
     */
    private function returnPath(Request $request): string
    {
        $target = (string) $request->input('redirect_to', '');

        return preg_match('#^/attendance(/|$|\?)#', $target) ? $target : route('pos.index', absolute: false);
    }
}
