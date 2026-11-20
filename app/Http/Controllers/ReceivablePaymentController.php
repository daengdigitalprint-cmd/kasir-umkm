<?php

namespace App\Http\Controllers;

use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\ReceivableCollector;
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
            'payment_method' => 'required|string|in:'.implode(',', ReceivableCollector::METHODS),
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

            // Pastikan periode akuntansi terbuka
            AccountingPeriodGuard::assertDateOpen($store, $validated['received_at']);

            $payment = ReceivableCollector::apply($store, $user, $transaction, (float) $validated['amount'], $validated);
            if (! $payment) {
                return back()->with('error', 'Nominal pelunasan tidak valid.');
            }

            $paymentAmount = (float) $payment->amount;
            $newStatus = $transaction->payment_status;
            $newOutstanding = ReceivableCollector::outstanding($transaction);

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
