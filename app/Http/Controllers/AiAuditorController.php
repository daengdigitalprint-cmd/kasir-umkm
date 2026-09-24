<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AuditorAgent;
use App\Models\AiSetting;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiAuditorController extends Controller
{
    /**
     * Batas maksimal rentang analisa (hari) untuk mengelola pemakaian token AI.
     */
    public const MAX_RANGE_DAYS = 31;

    /**
     * Batas jumlah transaksi per analisa agar payload JSON tetap ringkas.
     */
    public const MAX_TRANSACTIONS = 500;

    /**
     * Halaman AI Auditor (Super Admin).
     */
    public function index(Request $request): Response
    {
        // Daftar akun pemilik bisnis/penjual (user non-superadmin) untuk dropdown filter.
        $users = User::query()
            ->where('is_super_admin', false)
            ->with(['store.organization', 'stores.organization'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'store_id', 'role'])
            ->map(function (User $u) {
                // Prioritaskan toko dari pivot (multi-outlet), fallback ke store_id legacy.
                $organization = null;
                if ($u->stores->isNotEmpty()) {
                    $organization = $u->stores->firstWhere('organization_id', '!=', null)?->organization;
                }
                if (! $organization && $u->store) {
                    $organization = $u->store->organization;
                }

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'store_id' => $u->store_id,
                    'store_name' => $u->store?->name,
                    'role' => $u->role,
                    'group_name' => $organization?->name ?? 'Tanpa Grup',
                ];
            });

        return Inertia::render('AiAuditor/Index', [
            'users' => $users,
            'maxRangeDays' => self::MAX_RANGE_DAYS,
            'hasApiKey' => $this->hasApiKey(),
            'aiProvider' => AiSetting::activeProvider(),
        ]);
    }

    /**
     * Endpoint analisa: menarik data transaksi tenant terpilih, memformat JSON ringkas,
     * lalu memanggil AuditorAgent (read-only, tanpa eksekusi SQL dari AI).
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'user_id.required' => 'Pilih akun user yang akan dianalisa.',
            'user_id.exists' => 'Akun user tidak ditemukan.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
        ]);

        $targetUser = User::query()
            ->where('is_super_admin', false)
            ->findOrFail($validated['user_id']);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->endOfDay();

        // Batasi rentang waktu maksimal untuk token management.
        if ($start->copy()->addDays(self::MAX_RANGE_DAYS)->lessThan($end)) {
            return response()->json([
                'success' => false,
                'message' => 'Rentang tanggal terlalu panjang. Maksimal '.self::MAX_RANGE_DAYS.' hari per analisa.',
            ], 422);
        }

        if (! $this->hasApiKey()) {
            return response()->json([
                'success' => false,
                'message' => 'API Key AI belum dikonfigurasi. Atur melalui menu Pengaturan AI di dashboard Super Admin.',
            ], 503);
        }

        // Terapkan provider & API key dari halaman pengaturan ke config runtime.
        AiSetting::applyToRuntime();

        $storeIds = $targetUser->stores()->pluck('stores.id');
        if ($targetUser->store_id) {
            $storeIds->push($targetUser->store_id);
        }
        $storeIds = $storeIds->unique()->values();

        if ($storeIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'User ini tidak terhubung ke toko manapun, tidak ada data yang bisa dianalisa.',
            ], 422);
        }

        // Ambil transaksi user terpilih pada rentang tanggal (batasi jumlah untuk token).
        $transactions = Transaction::query()
            ->with(['items', 'user', 'store'])
            ->where('user_id', $targetUser->id)
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->limit(self::MAX_TRANSACTIONS)
            ->get();

        // Ringkasan agregat kas: shift & transaksi kas user terkait di toko yang sama.
        $shifts = CashShift::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('opened_at', [$start, $end])
            ->orderBy('opened_at')
            ->limit(200)
            ->get();

        $cashTransactions = CashTransaction::query()
            ->with('user:id,name')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        // Format data JSON ringkas (data minimization: kolom sensitif dibuang).
        $payload = [
            'meta' => [
                'analysis_type' => 'transaction_cash_stock_audit',
                'generated_at' => now()->toIso8601String(),
                'date_range' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                'target_user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'role' => $targetUser->role,
                ],
                'currency' => 'IDR',
            ],
            'summary' => $this->buildSummary($transactions, $shifts, $cashTransactions),
            'transactions' => $this->formatTransactions($transactions),
            'cash_shifts' => $this->formatShifts($shifts),
            'cash_transactions' => $this->formatCashTransactions($cashTransactions),
        ];

        try {
            $model = AiSetting::get('model');
            $response = AuditorAgent::make()->prompt(
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                model: $model ?: null,
            );
            $structured = $response->structured;
        } catch (Throwable $e) {
            report($e);

            $provider = AiSetting::activeProvider();
            $displayModel = $model ?: 'default';

            $hint = '';
            if (str_contains($e->getMessage(), 'model_not_found')) {
                $hint = ' Model "'.$displayModel.'" tidak tersedia di provider '.$provider.'. Periksa halaman Pengaturan AI dan gunakan model yang didukung oleh provider Anda.';
            }

            return response()->json([
                'success' => false,
                'message' => 'Analisa AI gagal dijalankan: '.$e->getMessage().$hint,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'summary' => $structured['summary'] ?? '',
            'anomalies' => $structured['anomalies'] ?? [],
            'usage' => [
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
            ],
        ]);
    }

    /**
     * Apakah API key AI sudah dikonfigurasi (dari halaman pengaturan atau .env).
     */
    private function hasApiKey(): bool
    {
        return AiSetting::apiKey() !== null;
    }

    /**
     * Ringkasan agregat penjualan & kas untuk membantu AI memvalidasi sinkronisasi.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $transactions
     * @param  \Illuminate\Support\Collection<int, CashShift>  $shifts
     * @param  \Illuminate\Support\Collection<int, CashTransaction>  $cashTransactions
     */
    private function buildSummary($transactions, $shifts, $cashTransactions): array
    {
        $completed = $transactions->where('status', 'completed');

        $totalSales = (float) $completed->sum('total_amount');
        $cashSales = (float) $completed->where('payment_method', 'cash')->sum('total_amount');
        $voided = $transactions->filter(fn ($t) => (bool) $t->is_void);

        $totalCost = 0.0;
        $itemsBelowCost = 0;
        foreach ($completed as $transaction) {
            foreach ($transaction->items as $item) {
                $totalCost += (float) $item->total_cost_snapshot;

                if ($item->unit_cost_snapshot !== null && (float) $item->unit_price < (float) $item->unit_cost_snapshot) {
                    $itemsBelowCost++;
                }
            }
        }

        return [
            'transactions_total' => $transactions->count(),
            'transactions_completed' => $completed->count(),
            'transactions_voided' => $voided->count(),
            'total_sales_amount' => round($totalSales, 2),
            'cash_sales_amount' => round($cashSales, 2),
            'total_cost_of_goods_sold' => round($totalCost, 2),
            'items_sold_below_cost' => $itemsBelowCost,
            'cash_shifts_count' => $shifts->count(),
            'cash_shifts_with_difference' => $shifts->filter(fn ($s) => $s->closed_at !== null && abs((float) $s->difference_amount) > 0.01)->count(),
            'cash_shifts_total_difference' => round((float) $shifts->where('closed_at', '!=', null)->sum('difference_amount'), 2),
            'cash_in_total' => round((float) $cashTransactions->where('type', 'in')->sum('amount'), 2),
            'cash_out_total' => round((float) $cashTransactions->where('type', 'out')->sum('amount'), 2),
        ];
    }

    /**
     * Format transaksi menjadi JSON ringkas (data minimization).
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $transactions
     */
    private function formatTransactions($transactions): array
    {
        return $transactions->map(fn (Transaction $t) => [
            'number' => $t->transaction_number,
            'date' => $t->created_at?->toIso8601String(),
            'store' => $t->store?->name,
            'user' => $t->user?->name,
            'status' => $t->status,
            'is_void' => (bool) $t->is_void,
            'void_reason' => $t->void_reason,
            'payment_method' => $t->payment_method,
            'payment_status' => $t->payment_status,
            'subtotal' => (float) $t->subtotal,
            'discount_amount' => (float) $t->discount_amount,
            'tax_amount' => (float) $t->tax_amount,
            'total_amount' => (float) $t->total_amount,
            'amount_paid' => (float) $t->amount_paid,
            'items' => $t->items->map(fn (TransactionItem $i) => [
                'product' => $i->product_name,
                'qty' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'unit_cost' => $i->unit_cost_snapshot !== null ? (float) $i->unit_cost_snapshot : null,
                'total_price' => (float) $i->total_price,
            ]),
        ])->all();
    }

    /**
     * Format shift kas untuk JSON ringkas.
     *
     * @param  \Illuminate\Support\Collection<int, CashShift>  $shifts
     */
    private function formatShifts($shifts): array
    {
        return $shifts->map(fn (CashShift $s) => [
            'shift_number' => $s->shift_number,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'user' => $s->user?->name,
            'opening_cash' => (float) $s->opening_cash,
            'expected_cash' => (float) $s->expected_cash,
            'closing_cash_counted' => $s->closing_cash_counted !== null ? (float) $s->closing_cash_counted : null,
            'difference_amount' => $s->difference_amount !== null ? (float) $s->difference_amount : null,
            'notes' => $s->closing_notes,
        ])->all();
    }

    /**
     * Format transaksi kas untuk JSON ringkas (tanpa data sensitif).
     *
     * @param  \Illuminate\Support\Collection<int, CashTransaction>  $cashTransactions
     */
    private function formatCashTransactions($cashTransactions): array
    {
        return $cashTransactions->map(fn (CashTransaction $c) => [
            'date' => $c->created_at?->toIso8601String(),
            'type' => $c->type,
            'category' => $c->category,
            'amount' => (float) $c->amount,
            'user' => $c->user?->name,
            'notes' => $c->notes,
        ])->all();
    }
}
