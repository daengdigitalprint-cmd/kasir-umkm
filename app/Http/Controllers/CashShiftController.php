<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\Transaction;
use App\Services\ActivityLogger;
use App\Support\StorePlanLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CashShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup shift kasir. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $isPinMode = ($store->cashier_login_mode ?? 'email') === 'pin';
        $activeCashier = $request->session()->get('active_cashier');

        if ($isPinMode && $activeCashier) {
            $openShift = CashShift::query()
                ->where('store_id', $store->id)
                ->whereNull('closed_at')
                ->where('employee_id', $activeCashier['employee_id'])
                ->first();

            if (! $openShift) {
                $openShift = CashShift::query()
                    ->where('store_id', $store->id)
                    ->whereNull('closed_at')
                    ->where('user_id', $user->id)
                    ->first();
            }
        } else {
            $openShift = CashShift::query()
                ->where('store_id', $store->id)
                ->whereNull('closed_at')
                ->where('user_id', $user->id)
                ->first();
        }

        $shiftsQuery = CashShift::query()
            ->where('store_id', $store->id)
            ->with('user:id,name', 'employee:id,name');

        if ($isPinMode && $activeCashier) {
            $shiftsQuery->where('employee_id', $activeCashier['employee_id']);
        } else {
            $shiftsQuery->where('user_id', $user->id);
        }

        $shifts = $shiftsQuery->orderByDesc('opened_at')->limit(100)->get();

        $shiftIds = $shifts->pluck('id');

        // 1) Transaction sum per cash_shift_id (cash, completed)
        $salesCashByShift = [];
        if ($shiftIds->isNotEmpty()) {
            $rows = Transaction::query()
                ->whereIn('cash_shift_id', $shiftIds)
                ->where('status', 'completed')
                ->select('cash_shift_id', \Illuminate\Support\Facades\DB::raw('SUM(cash_amount) as total'))
                ->groupBy('cash_shift_id')
                ->get()
                ->pluck('total', 'cash_shift_id');
            foreach ($rows as $shiftId => $total) {
                $salesCashByShift[$shiftId] = (float) $total;
            }
        }

        // 2) CashTransaction sum per cash_shift_id, type='in'
        $cashInByShift = [];
        if ($shiftIds->isNotEmpty()) {
            $rows = \App\Models\CashTransaction::query()
                ->whereIn('cash_shift_id', $shiftIds)
                ->where('type', 'in')
                ->select('cash_shift_id', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
                ->groupBy('cash_shift_id')
                ->get()
                ->pluck('total', 'cash_shift_id');
            foreach ($rows as $shiftId => $total) {
                $cashInByShift[$shiftId] = (float) $total;
            }
        }

        // 3) CashTransaction sum per cash_shift_id, type='out'
        $cashOutByShift = [];
        if ($shiftIds->isNotEmpty()) {
            $rows = \App\Models\CashTransaction::query()
                ->whereIn('cash_shift_id', $shiftIds)
                ->where('type', 'out')
                ->select('cash_shift_id', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
                ->groupBy('cash_shift_id')
                ->get()
                ->pluck('total', 'cash_shift_id');
            foreach ($rows as $shiftId => $total) {
                $cashOutByShift[$shiftId] = (float) $total;
            }
        }

        // 4) Transaction count per cash_shift_id (completed) for withCount replacement
        $transactionsCountByShift = [];
        if ($shiftIds->isNotEmpty()) {
            $rows = Transaction::query()
                ->whereIn('cash_shift_id', $shiftIds)
                ->where('status', 'completed')
                ->select('cash_shift_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as cnt'))
                ->groupBy('cash_shift_id')
                ->get()
                ->pluck('cnt', 'cash_shift_id');
            foreach ($rows as $shiftId => $cnt) {
                $transactionsCountByShift[$shiftId] = (int) $cnt;
            }
        }

        $shifts = $shifts->map(function (CashShift $shift) use ($salesCashByShift, $cashInByShift, $cashOutByShift, $transactionsCountByShift) {
            $salesCash = $salesCashByShift[$shift->id] ?? 0.0;
            $cashIn = $cashInByShift[$shift->id] ?? 0.0;
            $cashOut = $cashOutByShift[$shift->id] ?? 0.0;
            $expected = (float) $shift->opening_cash + $salesCash + $cashIn - $cashOut;

            return [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'user_name' => $shift->employee?->name ?? $shift->user?->name ?? null,
                'opened_at' => $shift->opened_at?->toIso8601ZuluString(),
                'closed_at' => $shift->closed_at?->toIso8601ZuluString(),
                'opening_cash' => (float) $shift->opening_cash,
                'expected_cash' => $expected,
                'closing_cash_counted' => $shift->closing_cash_counted !== null ? (float) $shift->closing_cash_counted : null,
                'difference_amount' => $shift->difference_amount !== null ? (float) $shift->difference_amount : null,
                'closing_notes' => $shift->closing_notes,
                'transactions_count' => $transactionsCountByShift[$shift->id] ?? 0,
            ];
        })
            ->values()
            ->all();

        $openShiftPayload = null;
        if ($openShift) {
            $salesCashOpen = (float) Transaction::query()
                ->where('cash_shift_id', $openShift->id)
                ->where('status', 'completed')
                ->sum('cash_amount');

            $cashInOpen = (float) \App\Models\CashTransaction::query()
                ->where('cash_shift_id', $openShift->id)
                ->where('type', 'in')
                ->sum('amount');

            $cashOutOpen = (float) \App\Models\CashTransaction::query()
                ->where('cash_shift_id', $openShift->id)
                ->where('type', 'out')
                ->sum('amount');

            $expectedOpen = (float) $openShift->opening_cash + $salesCashOpen + $cashInOpen - $cashOutOpen;

            $openShiftPayload = [
                'id' => $openShift->id,
                'shift_number' => $openShift->shift_number,
                'opened_at' => $openShift->opened_at?->toIso8601ZuluString(),
                'opening_cash' => (float) $openShift->opening_cash,
                'expected_cash' => $expectedOpen,
            ];
        }

        // Deteksi shift aktif oleh kasir lain di outlet yang sama (untuk info UI).
        // Selama ada shift aktif milik kasir lain, kasir ini tidak boleh membuka shift baru.
        // Admin dan Owner tetap bisa melihat ini untuk menutupnya jika perlu.
        $activeShiftByOther = null;
        if (! $openShift) {
            $blocker = CashShift::query()
                ->where('store_id', $store->id)
                ->whereNull('closed_at')
                ->first();

            if ($blocker && (int) $blocker->user_id !== (int) $user->id) {
                if ($user->canManageStoreSettings() || $user->isOwner()) {
                    // Admin atau owner bisa bertindak pada shift blocker
                    $openShiftPayload = [
                        'id' => $blocker->id,
                        'shift_number' => $blocker->shift_number,
                        'opened_at' => $blocker->opened_at?->toIso8601ZuluString(),
                        'opening_cash' => (float) $blocker->opening_cash,
                        // Hitung expected_cash untuk shift blocker agar admin bisa melihat nominalnya saat mau tutup
                        'expected_cash' => (float) $blocker->opening_cash + (float) Transaction::query()->where('cash_shift_id', $blocker->id)->where('status', 'completed')->sum('cash_amount') + (float) \App\Models\CashTransaction::query()->where('cash_shift_id', $blocker->id)->where('type', 'in')->sum('amount') - (float) \App\Models\CashTransaction::query()->where('cash_shift_id', $blocker->id)->where('type', 'out')->sum('amount'),
                    ];
                } else {
                    $activeShiftByOther = [
                        'shift_number' => $blocker->shift_number,
                        'user_name' => $blocker->user?->name ?? 'Kasir lain',
                        'opened_at' => $blocker->opened_at?->toIso8601ZuluString(),
                    ];
                }
            }
        }

        return Inertia::render('CashShifts/Index', [
            'shifts' => $shifts,
            'openShift' => $openShiftPayload,
            'activeShiftByOther' => $activeShiftByOther,
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup shift kasir. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $isPinMode = ($store->cashier_login_mode ?? 'email') === 'pin';
        $activeCashier = $request->session()->get('active_cashier');

        // Aturan sistem: hanya satu shift aktif per outlet.
        // Jika sudah ada shift yang masih berjalan (oleh kasir mana pun di outlet yang sama),
        // kasir lain TIDAK BOLEH membuka shift baru sampai shift tersebut ditutup.
        $activeShiftQuery = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at');

        if ($isPinMode && $activeCashier) {
            $activeShiftQuery->where('employee_id', $activeCashier['employee_id']);
        } else {
            $activeShiftQuery->where('user_id', $user->id);
        }

        $activeShift = $activeShiftQuery->first();

        if ($activeShift) {
            return redirect()
                ->route('cash-shifts.index')
                ->with('warning', 'Anda sudah memiliki shift terbuka.');
        }

        // Cek juga shift aktif oleh kasir lain (untuk info pemblokiran)
        $blocker = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->first();

        if ($blocker) {
            $blockerName = $blocker->employee?->name ?? $blocker->user?->name ?? 'Kasir lain';

            return redirect()
                ->route('cash-shifts.index')
                ->with('shift_blocked', [
                    'shift_number' => $blocker->shift_number,
                    'user_name' => $blockerName,
                    'opened_at' => $blocker->opened_at?->toIso8601ZuluString(),
                    'store_name' => $store->name,
                ]);
        }

        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $shiftData = [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'opening_cash' => $validated['opening_cash'],
            'opened_at' => now(),
        ];

        // Set employee_id jika PIN mode aktif
        if ($isPinMode && $activeCashier) {
            $shiftData['employee_id'] = $activeCashier['employee_id'];
        }

        $shift = new CashShift($shiftData);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $shift->shift_number = null;
                $shift->save();

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }

                $shift = new CashShift($shiftData);
            }
        }

        return redirect()
            ->route('pos.index')
            ->with('success', 'Shift kasir dibuka.');
    }

    public function close(Request $request, CashShift $cashShift)
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store || (int) $cashShift->store_id !== (int) $store->id) {
            abort(403);
        }

        $isPinMode = ($store->cashier_login_mode ?? 'email') === 'pin';
        $activeCashier = $request->session()->get('active_cashier');

        if ($isPinMode && $activeCashier) {
            // PIN mode: cek employee_id jika shift punya employee_id
            if ($cashShift->employee_id) {
                if ((int) $cashShift->employee_id !== (int) $activeCashier['employee_id'] && ! $user->canManageStoreSettings() && ! $user->isOwner()) {
                    abort(403);
                }
            } else {
                // Shift lama tanpa employee_id: cek user_id
                if ((int) $cashShift->user_id !== (int) $user->id && ! $user->canManageStoreSettings() && ! $user->isOwner()) {
                    abort(403);
                }
            }
        } elseif ((int) $cashShift->user_id !== (int) $user->id && ! $user->canManageStoreSettings() && ! $user->isOwner()) {
            abort(403);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return $this->jsonRedirect(
                $request,
                redirect()->route('dashboard')->with('warning', 'Paket Starter tidak mencakup shift kasir. Upgrade ke paket GrowUp di halaman langganan.')
            );
        }

        if ($cashShift->closed_at) {
            return $this->jsonRedirect(
                $request,
                redirect()->route('cash-shifts.index')->with('warning', 'Shift sudah ditutup.')
            );
        }

        if ($cashShift->opened_at && now()->lt($cashShift->opened_at)) {
            return $this->jsonRedirect(
                $request,
                redirect()->route('cash-shifts.index')->with('error', 'Waktu sistem tidak valid: penutupan shift tidak boleh sebelum waktu pembukaan.')
            );
        }

        $validated = $request->validate([
            'closing_cash_counted' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $salesCash = (float) Transaction::query()
            ->where('cash_shift_id', $cashShift->id)
            ->where('status', 'completed')
            ->sum('cash_amount');

        $cashIn = (float) \App\Models\CashTransaction::query()
            ->where('cash_shift_id', $cashShift->id)
            ->where('type', 'in')
            ->sum('amount');

        $cashOut = (float) \App\Models\CashTransaction::query()
            ->where('cash_shift_id', $cashShift->id)
            ->where('type', 'out')
            ->sum('amount');

        $expected = (float) $cashShift->opening_cash + $salesCash + $cashIn - $cashOut;

        $counted = (float) $validated['closing_cash_counted'];
        $diff = round($counted - $expected, 2);

        $cashShift->update([
            'closed_at' => now(),
            'expected_cash' => $expected,
            'closing_cash_counted' => $counted,
            'difference_amount' => $diff,
            'closing_notes' => $validated['closing_notes'] ?? null,
        ]);

        // Catat penutupan shift ke activity log (audit trail)
        ActivityLogger::log(
            store: $store,
            action: 'cash_shift.closed',
            description: "Shift #{$cashShift->id} ditutup oleh {$user->name}. Selisih kas: ".number_format($diff, 0, ',', '.'),
            subject: $cashShift,
            metadata: [
                'opening_cash' => (float) $cashShift->opening_cash,
                'expected_cash' => $expected,
                'closing_cash_counted' => $counted,
                'difference_amount' => $diff,
                'opened_by' => $cashShift->opened_by,
                'closed_by' => $user->id,
            ],
            user: $user
        );

        // Mode PIN: setelah shift ditutup, kasir dianggap selesai bertugas.
        // Hapus active_cashier agar saat masuk POS kembali aplikasi meminta PIN ulang.
        if ($isPinMode) {
            $request->session()->forget('active_cashier');
        }

        $response = redirect()
            ->route('cash-shifts.index')
            ->with('success', 'Shift kasir ditutup. Selisih: '.number_format($diff, 0, ',', '.'));

        return $this->jsonRedirect($request, $response, [
            'cash_shift' => [
                'id' => $cashShift->id,
                'closed_at' => $cashShift->fresh()->closed_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Helper: kirim redirect sebagai response normal untuk form submit,
     * atau sebagai JSON untuk permintaan AJAX (POS inline close shift).
     *
     * @param  \Illuminate\Http\RedirectResponse  $redirect
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function jsonRedirect(Request $request, $redirect, array $extra = [])
    {
        // Permintaan Inertia harus menerima redirect biasa, bukan JSON.
        if ($request->header('X-Inertia')) {
            return $redirect;
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $redirect->getSession()->get('success') ?? 'Shift ditutup.',
                'redirect' => $redirect->getTargetUrl(),
                ...$extra,
            ]);
        }

        return $redirect;
    }
}
