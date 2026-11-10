<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\JournalPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint penerimaan pelunasan piutang pelanggan.
 *
 * Jurnal yang diposting:
 *   Debit  → Kas/Bank      (akun sesuai payment_method)
 *   Credit → Piutang Usaha (1.3.01)
 */
class ReceivablePaymentController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
            'amount' => 'required|numeric|min:0.01|max:99999999999.99',
            'payment_method' => 'required|string|in:cash,card,ewallet,transfer,other',
            'received_at' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        // Periksa duplikasi via idempotency_key
        if (! empty($validated['idempotency_key'])) {
            $duplicate = ReceivablePayment::where('store_id', $store->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if ($duplicate) {
                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Pembayaran sudah tercatat sebelumnya.',
                ]);
            }
        }

        return DB::transaction(function () use ($store, $user, $validated, $request) {
            // Kunci transaksi target agar tidak ada race condition
            $transaction = Transaction::where('store_id', $store->id)
                ->where('id', $validated['transaction_id'])
                ->where('status', 'completed')
                ->whereNotNull('customer_id')
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                abort(404, 'Transaksi tidak ditemukan atau tidak memenuhi syarat pelunasan.');
            }

            $outstanding = round((float) $transaction->total_amount - (float) $transaction->amount_paid, 2);

            if ($outstanding <= 0) {
                return back()->with('error', 'Transaksi ini sudah lunas.');
            }

            $paymentAmount = round(min((float) $validated['amount'], $outstanding), 2);

            if ($paymentAmount <= 0) {
                return back()->with('error', 'Nominal pelunasan tidak valid.');
            }

            // Pastikan periode akuntansi terbuka
            AccountingPeriodGuard::assertDateOpen($store, $validated['received_at']);

            // Hubungkan ke shift kasir aktif jika pembayaran tunai
            $cashShiftId = null;
            if ($validated['payment_method'] === 'cash') {
                $cashShiftId = CashShift::where('store_id', $store->id)
                    ->where('user_id', $user->id)
                    ->whereNull('closed_at')
                    ->value('id');
            }

            $payment = ReceivablePayment::create([
                'transaction_id' => $transaction->id,
                'store_id' => $store->id,
                'customer_id' => $transaction->customer_id,
                'user_id' => $user->id,
                'cash_shift_id' => $cashShiftId,
                'amount' => $paymentAmount,
                'payment_method' => $validated['payment_method'],
                'received_at' => $validated['received_at'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'created_by' => $user->id,
            ]);

            // Update saldo transaksi
            $newAmountPaid = round((float) $transaction->amount_paid + $paymentAmount, 2);
            $newOutstanding = round((float) $transaction->total_amount - $newAmountPaid, 2);
            $newStatus = $newOutstanding <= 0.009 ? 'paid' : 'partial';

            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $newStatus,
            ]);

            // Posting jurnal pelunasan
            $payment->load('transaction');
            JournalPoster::postReceivableCollection($store, $payment);

            $remainingAfter = max(0, $newOutstanding);
            $msg = $newStatus === 'paid'
                ? 'Piutang transaksi '.$transaction->transaction_number.' telah lunas.'
                : 'Pembayaran Rp '.number_format($paymentAmount, 0, ',', '.').' berhasil dicatat. Sisa piutang: Rp '.number_format($remainingAfter, 0, ',', '.').'.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'payment_status' => $newStatus,
                    'outstanding' => $remainingAfter,
                ]);
            }

            return back()->with('success', $msg);
        });
    }
}
