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

        // Hitung buta: staf tidak melihat perkiraan kas maupun selisih, termasuk di riwayat.
        $canSeeCash = $user->canAccessBackOffice();
        if (! $canSeeCash) {
            $shifts = array_map(fn (array $row) => array_merge($row, [
                'expected_cash' => null,
                'difference_amount' => null,
            ]), $shifts);
        }

        $openShiftPayload = $openShift ? $this->openShiftPayload($openShift, $user, true) : null;

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
                    // Admin atau owner bisa menutup shift orang lain yang memblokir outlet.
                    $openShiftPayload = $this->openShiftPayload($blocker, $user, false);
                } else {
                    $activeShiftByOther = [
                        'shift_number' => $blocker->shift_number,
                        'user_name' => $blocker->employee?->name ?? $blocker->user?->name ?? 'Kasir lain',
                        'opened_at' => $blocker->opened_at?->toIso8601ZuluString(),
                        'hours_open' => $this->hoursOpen($blocker),
                    ];
                }
            }
        }

        // Saran kas awal: hasil hitung uang laci pada shift terakhir yang ditutup di outlet ini.
        $lastClosed = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereNotNull('closing_cash_counted')
            ->orderByDesc('closed_at')
            ->first();

        // Ringkasan shift yang baru saja ditutup (?closed=ID setelah tutup shift). Lewat URL, bukan flash,
        // karena polling layout bisa "memakan" flash sebelum halaman tujuan dimuat.
        $justClosed = null;
        $justClosedId = (int) $request->query('closed', 0);
        if ($justClosedId > 0) {
            $closed = CashShift::query()
                ->where('store_id', $store->id)
                ->whereKey($justClosedId)
                ->whereNotNull('closed_at')
                ->where('closed_at', '>=', now()->subHours(2))
                ->first();
            if ($closed && $this->canActOnShift($request, $user, $store, $closed)) {
                $justClosed = [
                    'id' => $closed->id,
                    'shift_number' => $closed->shift_number,
                    'closing_cash_counted' => (float) $closed->closing_cash_counted,
                    'difference_amount' => $canSeeCash && $closed->difference_amount !== null ? (float) $closed->difference_amount : null,
                    'print_url' => route('cash-shifts.print', $closed),
                ];
            }
        }

        return Inertia::render('CashShifts/Index', [
            'shifts' => $shifts,
            'openShift' => $openShiftPayload,
            'activeShiftByOther' => $activeShiftByOther,
            'timezone' => $store->timezone ?: 'Asia/Jakarta',
            'canViewAllShifts' => $canSeeCash,
            'suggestedOpeningCash' => $lastClosed ? (float) $lastClosed->closing_cash_counted : null,
            'longOpenHours' => self::LONG_OPEN_HOURS,
            'justClosed' => $justClosed,
            'signatureEnabled' => self::signatureEnabled($store),
        ]);
    }

    /**
     * Laporan shift siap cetak (struk 58/80 mm atau kertas biasa).
     */
    public function print(Request $request, CashShift $cashShift)
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store || (int) $cashShift->store_id !== (int) $store->id || ! $this->canActOnShift($request, $user, $store, $cashShift)) {
            abort(403);
        }

        $cashShift->loadMissing('user:id,name', 'employee:id,name');
        $totals = $this->shiftTotals($cashShift);
        $canSeeCash = $user->canAccessBackOffice();

        $isClosed = $cashShift->closed_at !== null;
        $signatureEnabled = self::signatureEnabled($store);

        return view('cash-shifts.print', [
            'store' => $store,
            'shift' => $cashShift,
            'cashierName' => $cashShift->employee?->name ?? $cashShift->user?->name ?? 'Kasir',
            'timezone' => $store->timezone ?: 'Asia/Jakarta',
            'totals' => $totals,
            'payments' => $this->paymentRows($totals['payments'], $canSeeCash),
            'canSeeCash' => $canSeeCash,
            'signatureEnabled' => $signatureEnabled,
            'canSignCashier' => $signatureEnabled && $isClosed && ! $cashShift->cashier_signature && $this->isShiftOwner($request, $user, $store, $cashShift),
            'canSignManager' => $signatureEnabled && $isClosed && ! $cashShift->manager_signature && $canSeeCash,
            'managerName' => $user->name,
        ]);
    }

    /**
     * Simpan tanda tangan digital kasir (penyerah kas) atau pengelola (penerima kas) pada shift yang sudah ditutup.
     * Tanda tangan yang sudah tersimpan tidak bisa ditimpa.
     */
    public function sign(Request $request, CashShift $cashShift)
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store || (int) $cashShift->store_id !== (int) $store->id) {
            abort(403);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:cashier,manager'],
            'signature' => ['required', 'string', 'max:400000', 'starts_with:data:image/png;base64,'],
        ]);

        if (! self::signatureEnabled($store)) {
            return response()->json(['message' => 'Tanda tangan digital sedang dinonaktifkan pemilik toko.'], 422);
        }

        if ($cashShift->closed_at === null) {
            return response()->json(['message' => 'Tanda tangan hanya bisa dibuat setelah shift ditutup.'], 422);
        }

        $binary = base64_decode(substr($validated['signature'], strlen('data:image/png;base64,')), true);
        if ($binary === false || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n") || strlen($binary) < 200) {
            return response()->json(['message' => 'Tanda tangan tidak terbaca. Coba tanda tangan lagi.'], 422);
        }

        if ($validated['role'] === 'cashier') {
            if (! $this->isShiftOwner($request, $user, $store, $cashShift)) {
                abort(403, 'Hanya kasir pemilik shift yang bisa menandatangani sebagai kasir.');
            }
            if ($cashShift->cashier_signature) {
                return response()->json(['message' => 'Kasir sudah menandatangani shift ini.'], 422);
            }
            $cashShift->update([
                'cashier_signature' => $validated['signature'],
                'cashier_signed_name' => $cashShift->employee?->name ?? $cashShift->user?->name ?? $user->name,
                'cashier_signed_at' => now(),
            ]);
        } else {
            if (! $user->canAccessBackOffice()) {
                abort(403, 'Hanya pengelola yang bisa menandatangani sebagai penerima kas.');
            }
            if ($cashShift->manager_signature) {
                return response()->json(['message' => 'Pengelola sudah menandatangani shift ini.'], 422);
            }
            $cashShift->update([
                'manager_signature' => $validated['signature'],
                'manager_signed_name' => $user->name,
                'manager_signed_by' => $user->id,
                'manager_signed_at' => now(),
            ]);
        }

        ActivityLogger::log(
            store: $store,
            action: 'cash_shift.signed',
            description: "Shift {$cashShift->shift_number} ditandatangani sebagai ".($validated['role'] === 'cashier' ? 'kasir' : 'pengelola')." oleh {$user->name}.",
            subject: $cashShift,
            metadata: ['role' => $validated['role']],
            user: $user
        );

        return response()->json(['message' => 'Tanda tangan tersimpan.']);
    }

    /**
     * Aktifkan/nonaktifkan tanda tangan digital di laporan shift (pemilik, admin, manajer).
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store || ! $user->canAccessBackOffice()) {
            abort(403);
        }

        $validated = $request->validate([
            'signature_enabled' => ['required', 'boolean'],
        ]);

        $settings = $store->settings ?? [];
        $settings['shift_digital_signature'] = (bool) $validated['signature_enabled'];
        $store->update(['settings' => $settings]);

        ActivityLogger::log(
            store: $store,
            action: 'cash_shift.settings',
            description: 'Tanda tangan digital laporan shift '.($settings['shift_digital_signature'] ? 'diaktifkan' : 'dinonaktifkan')." oleh {$user->name}.",
            user: $user
        );

        return back()->with('success', $settings['shift_digital_signature']
            ? 'Tanda tangan digital di laporan shift diaktifkan.'
            : 'Tanda tangan digital di laporan shift dinonaktifkan.');
    }

    /** Tanda tangan digital aktif kecuali pemilik menonaktifkannya (bawaan: aktif). */
    public static function signatureEnabled($store): bool
    {
        return (bool) (($store->settings ?? [])['shift_digital_signature'] ?? true);
    }

    /** Kasir pemilik shift (user, atau karyawan PIN yang sedang aktif). */
    private function isShiftOwner(Request $request, $user, $store, CashShift $cashShift): bool
    {
        $isPinMode = ($store->cashier_login_mode ?? 'email') === 'pin';
        $activeCashier = $request->session()->get('active_cashier');

        if ($isPinMode && $activeCashier && $cashShift->employee_id) {
            return (int) $cashShift->employee_id === (int) $activeCashier['employee_id'];
        }

        return (int) $cashShift->user_id === (int) $user->id;
    }

    /** Batas selisih kas (Rp) yang mewajibkan catatan alasan saat tutup shift. */
    public const NOTE_REQUIRED_DIFFERENCE = 10000;

    /** Shift yang terbuka lebih lama dari ini dianggap kemungkinan lupa ditutup. */
    public const LONG_OPEN_HOURS = 16;

    private const PAYMENT_LABELS = [
        'cash' => 'Tunai',
        'qris' => 'QRIS',
        'ewallet' => 'E-wallet',
        'transfer' => 'Transfer',
        'bank_transfer' => 'Transfer bank',
        'card' => 'Kartu debit/kredit',
        'credit' => 'Bayar nanti',
        'other' => 'Lainnya',
    ];

    /**
     * Angka ringkas satu shift: penjualan per metode, kas masuk/keluar, perkiraan kas laci.
     *
     * @return array{transactions_count:int, sales_total:float, sales_cash:float, cash_in:float, cash_out:float, expected:float, payments:array<string,float>}
     */
    private function shiftTotals(CashShift $shift): array
    {
        $completed = Transaction::query()
            ->where('cash_shift_id', $shift->id)
            ->where('status', 'completed');

        $salesCash = (float) (clone $completed)->sum('cash_amount');
        $salesTotal = (float) (clone $completed)->sum('total_amount');
        $count = (int) (clone $completed)->count();

        $cashIn = (float) \App\Models\CashTransaction::query()
            ->where('cash_shift_id', $shift->id)->where('type', 'in')->sum('amount');
        $cashOut = (float) \App\Models\CashTransaction::query()
            ->where('cash_shift_id', $shift->id)->where('type', 'out')->sum('amount');

        $payments = \App\Models\TransactionPayment::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_payments.transaction_id')
            ->where('transactions.cash_shift_id', $shift->id)
            ->where('transactions.status', 'completed')
            ->groupBy('transaction_payments.method')
            ->selectRaw('transaction_payments.method as method, SUM(transaction_payments.amount) as total')
            ->pluck('total', 'method')
            ->map(fn ($v) => (float) $v)
            ->all();

        return [
            'transactions_count' => $count,
            'sales_total' => $salesTotal,
            'sales_cash' => $salesCash,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected' => (float) $shift->opening_cash + $salesCash + $cashIn - $cashOut,
            'payments' => $payments,
        ];
    }

    /**
     * Rincian metode bayar untuk ditampilkan. Staf tidak melihat baris tunai (hitung buta).
     *
     * @param  array<string,float>  $payments
     * @return list<array{method:string,label:string,amount:float}>
     */
    private function paymentRows(array $payments, bool $canSeeCash): array
    {
        $rows = [];
        foreach ($payments as $method => $amount) {
            if ($amount <= 0 || (! $canSeeCash && $method === 'cash')) {
                continue;
            }
            $rows[] = [
                'method' => (string) $method,
                'label' => self::PAYMENT_LABELS[$method] ?? ucfirst((string) $method),
                'amount' => $amount,
            ];
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    private function openShiftPayload(CashShift $shift, $user, bool $isOwn): array
    {
        $shift->loadMissing('user:id,name', 'employee:id,name');
        $totals = $this->shiftTotals($shift);
        $canSeeCash = $user->canAccessBackOffice();

        return [
            'id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'owner_name' => $shift->employee?->name ?? $shift->user?->name ?? 'Kasir',
            'is_own' => $isOwn,
            'opened_at' => $shift->opened_at?->toIso8601ZuluString(),
            'hours_open' => $this->hoursOpen($shift),
            'opening_cash' => (float) $shift->opening_cash,
            // Hitung buta: kasir mengisi uang fisik tanpa melihat kas seharusnya.
            'expected_cash' => $canSeeCash ? $totals['expected'] : null,
            'transactions_count' => $totals['transactions_count'],
            'sales_total' => $canSeeCash ? $totals['sales_total'] : null,
            'cash_in' => $canSeeCash ? $totals['cash_in'] : null,
            'cash_out' => $canSeeCash ? $totals['cash_out'] : null,
            'payments' => $this->paymentRows($totals['payments'], $canSeeCash),
        ];
    }

    private function hoursOpen(CashShift $shift): int
    {
        return $shift->opened_at ? (int) floor($shift->opened_at->diffInMinutes(now()) / 60) : 0;
    }

    /** Pemilik shift (user atau karyawan PIN aktif), admin, atau owner boleh bertindak pada shift. */
    private function canActOnShift(Request $request, $user, $store, CashShift $cashShift): bool
    {
        return $user->canManageStoreSettings() || $user->isOwner() || $this->isShiftOwner($request, $user, $store, $cashShift);
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

        $expected = $this->shiftTotals($cashShift)['expected'];

        $counted = (float) $validated['closing_cash_counted'];
        $diff = round($counted - $expected, 2);

        // Selisih besar wajib disertai alasan. Kasir tetap tidak melihat angka perkiraan.
        if (abs($diff) > self::NOTE_REQUIRED_DIFFERENCE && trim((string) ($validated['closing_notes'] ?? '')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'closing_notes' => 'Hasil hitung berbeda cukup jauh dari catatan sistem. Hitung ulang uang di laci, atau tulis alasannya (mis. uang dipakai belanja, kembalian kurang) sebelum menutup shift.',
            ]);
        }

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
            ->route('cash-shifts.index', ['closed' => $cashShift->id])
            ->with('success', $user->canAccessBackOffice()
                ? 'Shift kasir ditutup. Selisih: '.number_format($diff, 0, ',', '.')
                : 'Shift kasir ditutup. Hasil hitung kas sudah dicatat untuk diperiksa pengelola.');

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
