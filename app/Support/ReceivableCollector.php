<?php

namespace App\Support;

use App\Models\CashShift;
use App\Models\ReceivablePayment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Accounting\JournalPoster;

/**
 * Mencatat pembayaran piutang pelanggan ke satu transaksi (bon).
 *
 * Dipakai oleh pembayaran per bon dan pembayaran per pelanggan (dialokasikan ke
 * bon tertua). Pemanggil wajib menjalankannya di dalam DB::transaction dan sudah
 * mengunci baris transaksi (lockForUpdate).
 *
 * Jurnal: Debit Kas/Bank (sesuai metode) — Kredit Piutang Usaha.
 */
class ReceivableCollector
{
    /** Metode bayar yang sama dengan layar bayar kasir (ditambah metode lama agar data lama tetap valid). */
    public const METHODS = ['cash', 'ewallet', 'other', 'qris', 'transfer', 'card'];

    public static function outstanding(Transaction $transaction): float
    {
        return round((float) $transaction->total_amount - (float) $transaction->amount_paid, 2);
    }

    /**
     * @param  array{payment_method: string, received_at: string, reference_number?: ?string, notes?: ?string, idempotency_key?: ?string}  $meta
     */
    public static function apply(Store $store, User $user, Transaction $transaction, float $amount, array $meta): ?ReceivablePayment
    {
        $paymentAmount = round(min($amount, self::outstanding($transaction)), 2);
        if ($paymentAmount <= 0) {
            return null;
        }

        // Pembayaran tunai masuk ke shift kasir yang sedang dibuka pengguna ini.
        $cashShiftId = $meta['payment_method'] === 'cash'
            ? CashShift::where('store_id', $store->id)->where('user_id', $user->id)->whereNull('closed_at')->value('id')
            : null;

        $payment = ReceivablePayment::create([
            'transaction_id' => $transaction->id,
            'store_id' => $store->id,
            'customer_id' => $transaction->customer_id,
            'user_id' => $user->id,
            'cash_shift_id' => $cashShiftId,
            'amount' => $paymentAmount,
            'payment_method' => $meta['payment_method'],
            'received_at' => $meta['received_at'],
            'reference_number' => $meta['reference_number'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'idempotency_key' => $meta['idempotency_key'] ?? null,
            'created_by' => $user->id,
        ]);

        $newAmountPaid = round((float) $transaction->amount_paid + $paymentAmount, 2);
        $transaction->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => round((float) $transaction->total_amount - $newAmountPaid, 2) <= 0.009 ? 'paid' : 'partial',
        ]);

        $payment->load('transaction');
        JournalPoster::postReceivableCollection($store, $payment);

        return $payment;
    }
}
