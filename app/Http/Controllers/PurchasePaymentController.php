<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint pelunasan cicilan/utang pembelian.
 *
 * Setiap pelunasan akan auto-post jurnal:
 *   Debit  → Utang Usaha (2.1.02)
 *   Credit → Kas/Bank (1.1.01 / 1.1.02)
 */
class PurchasePaymentController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        $validated = $request->validate([
            'purchase_id' => 'required|exists:purchases,id',
            'amount' => 'required|numeric|min:0.01|max:99999999999.99',
            'payment_method' => 'required|string|in:cash,transfer,ewallet',
            'payment_date' => 'required|date',
        ]);

        $purchase = Purchase::where('store_id', $store->id)->findOrFail($validated['purchase_id']);

        if ($purchase->is_paid) {
            return back()->with('error', 'Pembelian ini sudah lunas.');
        }

        $remaining = round((float) $purchase->total_amount - (float) $purchase->amount_paid, 2);
        $paymentAmount = round(min((float) $validated['amount'], $remaining), 2);

        if ($paymentAmount <= 0) {
            return back()->with('error', 'Tidak ada sisa utang yang perlu dilunasi.');
        }

        AccountingPeriodGuard::assertDateOpen($store, $validated['payment_date']);

        return DB::transaction(function () use ($store, $user, $purchase, $paymentAmount, $validated) {
            // Update purchase
            $newPaid = round((float) $purchase->amount_paid + $paymentAmount, 2);
            $isNowPaid = $newPaid >= (float) $purchase->total_amount;

            $purchase->update([
                'amount_paid' => $newPaid,
                'is_paid' => $isNowPaid,
                'payment_method' => $validated['payment_method'],
            ]);

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'user_id' => $user->id,
                'amount' => $paymentAmount,
                'payment_method' => $validated['payment_method'],
                'payment_date' => $validated['payment_date'],
            ]);

            // Posting jurnal: Debit Utang Usaha → Credit Kas/Bank (hanya jika modul akuntansi aktif)
            $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
            $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
            $utangUsaha = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_USAHA);

            if (AccountingFeatures::enabled($store) && $kas && $bank && $utangUsaha) {
                $payingAccount = $validated['payment_method'] === 'cash' ? $kas : $bank;
                $payingLabel = $validated['payment_method'] === 'cash' ? 'Kas' : 'Bank';

                $entry = JournalEntry::create([
                    'store_id' => $store->id,
                    'entry_date' => $validated['payment_date'],
                    'reference_type' => PurchasePayment::class,
                    'reference_id' => $payment->id,
                    'description' => 'Pelunasan pembelian '.$purchase->purchase_number,
                    'user_id' => $user->id,
                ]);

                // Debit: Utang Usaha (mengurangi utang)
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $utangUsaha->id,
                    'debit' => $paymentAmount,
                    'credit' => 0,
                    'memo' => 'Pelunasan cicilan pembelian '.$purchase->purchase_number,
                ]);

                // Credit: Kas/Bank (keluar)
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $payingAccount->id,
                    'debit' => 0,
                    'credit' => $paymentAmount,
                    'memo' => 'Pembayaran utang via '.$payingLabel,
                ]);
            }

            $remainingAfter = round((float) $purchase->total_amount - $newPaid, 2);
            $msg = $isNowPaid
                ? 'Pembelian '.$purchase->purchase_number.' telah lunas.'
                : 'Cicilan Rp '.number_format($paymentAmount, 0, ',', '.').' berhasil dicatat. Sisa utang: Rp '.number_format($remainingAfter, 0, ',', '.').'.';

            return back()->with('success', $msg);
        });
    }
}
