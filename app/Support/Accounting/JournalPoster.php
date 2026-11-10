<?php

namespace App\Support\Accounting;

use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Purchase;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JournalPoster
{
    /**
     * Guard pusat modul: jika modul akuntansi mati, semua posting no-op.
     * Satu tempat agar tidak ada titik posting yang terlewat.
     */
    public static function moduleEnabled(Store $store): bool
    {
        return AccountingFeatures::enabled($store);
    }

    public static function postSaleTransaction(Store $store, Transaction $transaction): void
    {
        if (! self::moduleEnabled($store)) {
            return;
        }

        if ($transaction->status !== 'completed') {
            return;
        }

        if ((float) $transaction->total_amount < 0.01) {
            return;
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $exists = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', Transaction::class)
            ->where('reference_id', $transaction->id)
            ->exists();

        if ($exists) {
            return;
        }

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
        $pendapatan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_PENJUALAN);
        $utangPpn = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_PPN_KELUARAN);
        $pendapatanTip = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_TIP);
        $hpp = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_HPP);
        $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);
        $piutangUsaha = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA);

        if (! $kas || ! $bank || ! $pendapatan || ! $utangPpn || ! $pendapatanTip || ! $hpp || ! $persediaan || ! $piutangUsaha) {
            Log::warning('accounting.missing_coa', ['store_id' => $store->id]);

            return;
        }

        // Pisah penerimaan Kas vs Bank (SAK EMKM); split tender memakai porsi tunai di cash_amount
        $paymentMethod = $transaction->payment_method ?? 'cash';

        $transaction->loadMissing(['items.product', 'items.productVariant']);

        $subtotal = (float) $transaction->subtotal;
        $discount = (float) $transaction->discount_amount;
        $service = (float) ($transaction->service_charge_amount ?? 0);
        $revenueExclTax = round(max(0, $subtotal - $discount + $service), 2);
        $taxAmount = round((float) $transaction->tax_amount, 2);
        $tipAmount = round((float) ($transaction->tip_amount ?? 0), 2);
        $totalAmount = round((float) $transaction->total_amount, 2);

        $cogsTotal = 0.0;
        foreach ($transaction->items as $item) {
            $cogsTotal += self::lineCogs($item);
        }
        $cogsTotal = round($cogsTotal, 2);

        // Pisahkan Kas/Bank (amount_paid) dan Piutang Usaha (outstanding)
        $amountPaid = round(max(0, (float) $transaction->amount_paid), 2);
        $changeAmount = round(max(0, (float) ($transaction->change_amount ?? 0)), 2);
        $cashReceived = round(max(0, $amountPaid - $changeAmount), 2); // uang bersih masuk
        $outstanding = round(max(0, $totalAmount - $cashReceived), 2);

        $cashAmount = (float) ($transaction->cash_amount ?? 0);
        $kasPart = $cashAmount > 0
            ? round(min($cashAmount, $cashReceived), 2)
            : ($paymentMethod === 'cash' ? $cashReceived : 0.0);
        $bankPart = round(max(0, $cashReceived - $kasPart), 2);

        $sumCredits = round($revenueExclTax + $taxAmount + $tipAmount + $cogsTotal, 2);
        $sumDebits = round($cashReceived + $outstanding + $cogsTotal, 2);

        if (abs($sumDebits - $sumCredits) > 0.02) {
            Log::warning('accounting.sale_unbalanced', [
                'transaction_id' => $transaction->id,
                'debits' => $sumDebits,
                'credits' => $sumCredits,
            ]);

            return;
        }

        $entryDate = $transaction->completed_at?->toDateString()
            ?? $transaction->created_at->toDateString();

        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        try {
            DB::transaction(function () use (
                $store,
                $transaction,
                $entryDate,
                $kas,
                $bank,
                $kasPart,
                $bankPart,
                $paymentMethod,
                $pendapatan,
                $utangPpn,
                $pendapatanTip,
                $hpp,
                $persediaan,
                $piutangUsaha,
                $revenueExclTax,
                $taxAmount,
                $tipAmount,
                $cogsTotal,
                $outstanding,
            ) {
                $entry = JournalEntry::query()->create([
                    'store_id' => $store->id,
                    'entry_date' => $entryDate,
                    'reference_type' => Transaction::class,
                    'reference_id' => $transaction->id,
                    'description' => 'Penjualan '.$transaction->transaction_number,
                    'user_id' => $transaction->user_id,
                ]);

                // Debit Kas/Bank — hanya sebesar uang yang benar-benar diterima
                if ($kasPart > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $kas->id,
                        'debit' => $kasPart,
                        'credit' => 0,
                        'memo' => 'Kas penerimaan cash',
                    ]);
                }

                if ($bankPart > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $bank->id,
                        'debit' => $bankPart,
                        'credit' => 0,
                        'memo' => 'Bank penerimaan '.($paymentMethod === 'cash' ? 'non-tunai' : $paymentMethod),
                    ]);
                }

                // Debit Piutang Usaha — sisa tagihan yang belum diterima
                if ($outstanding > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $piutangUsaha->id,
                        'debit' => $outstanding,
                        'credit' => 0,
                        'memo' => 'Piutang usaha ('.$transaction->transaction_number.')',
                    ]);
                }

                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $pendapatan->id,
                    'debit' => 0,
                    'credit' => $revenueExclTax,
                    'memo' => 'Pendapatan DPP (belum termasuk PPN)',
                ]);

                if ($taxAmount > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $utangPpn->id,
                        'debit' => 0,
                        'credit' => $taxAmount,
                        'memo' => 'Utang PPN Keluaran',
                    ]);
                }

                if ($tipAmount > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $pendapatanTip->id,
                        'debit' => 0,
                        'credit' => $tipAmount,
                        'memo' => 'Tip',
                    ]);
                }

                if ($cogsTotal > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $hpp->id,
                        'debit' => $cogsTotal,
                        'credit' => 0,
                        'memo' => 'HPP (metode perpetual sederhana)',
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $persediaan->id,
                        'debit' => 0,
                        'credit' => $cogsTotal,
                        'memo' => 'Pengurangan persediaan',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('accounting.post_sale_failed', [
                'transaction_id' => $transaction->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Posting setoran modal pemilik: Debit Kas, Kredit Modal.
     * Dipakai untuk mengisi saldo awal agar Neraca balance.
     */
    public static function postCapitalInjection(Store $store, float $amount, string $entryDate, ?int $userId = null, ?string $note = null): ?JournalEntry
    {
        if (! self::moduleEnabled($store)) {
            return null;
        }

        AccountingPeriodGuard::assertDateOpen($store, $entryDate);
        ChartOfAccountsBootstrap::ensureForStore($store);

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);

        if (! $kas || ! $modal) {
            throw new \RuntimeException('Akun Kas atau Modal tidak tersedia.');
        }

        $amount = round($amount, 2);

        return DB::transaction(function () use ($store, $amount, $entryDate, $userId, $note, $kas, $modal) {
            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $entryDate,
                'reference_type' => 'capital_injection',
                'reference_id' => null,
                'description' => $note ?: 'Setoran modal pemilik',
                'user_id' => $userId,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $kas->id,
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Penerimaan setoran modal ke kas',
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $modal->id,
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Modal pemilik',
            ]);

            return $entry;
        });
    }

    public static function postPurchase(Store $store, Purchase $purchase): void
    {
        if (! self::moduleEnabled($store)) {
            return;
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $exists = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->exists();

        if ($exists) {
            return;
        }

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
        $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);
        $ppnMasukan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PPN_MASUKAN);
        $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);
        $utangUsaha = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_USAHA);

        if (! $kas || ! $bank || ! $persediaan || ! $ppnMasukan || ! $beban || ! $utangUsaha) {
            return;
        }

        $purchase->loadMissing('lines', 'charges');

        $dpp = round((float) ($purchase->dpp_amount ?? 0), 2);
        $charges = round((float) ($purchase->charges_amount ?? 0), 2);
        $ppn = round((float) ($purchase->ppn_amount ?? 0), 2);
        $total = round((float) ($purchase->total_amount ?? ($dpp + $charges + $ppn)), 2);

        if ($dpp < 0.01 && $charges < 0.01 && $ppn < 0.01) {
            return;
        }

        $sumDr = round($dpp + $charges + $ppn, 2);
        if (abs($sumDr - $total) > 0.02) {
            Log::warning('accounting.purchase_total_mismatch', ['purchase_id' => $purchase->id]);

            return;
        }

        // Tentukan kredit: kas/bank jika lunas, utang usaha jika kredit
        $isPaid = $purchase->is_paid ?? true;
        $paymentMethod = $purchase->payment_method ?? 'cash';
        $amountPaid = round((float) ($purchase->amount_paid ?? ($isPaid ? $total : 0)), 2);
        $amountOwed = max(0, $total - $amountPaid);

        // Pilih akun kas/bank untuk bagian yang dibayar
        $payingAccount = $paymentMethod === 'cash' ? $kas : $bank;
        $payingLabel = $paymentMethod === 'cash' ? 'Kas' : 'Bank';

        $entryDate = $purchase->purchased_at?->toDateString() ?? $purchase->created_at->toDateString();
        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        try {
            DB::transaction(function () use (
                $store,
                $purchase,
                $entryDate,
                $payingAccount,
                $payingLabel,
                $persediaan,
                $ppnMasukan,
                $beban,
                $utangUsaha,
                $dpp,
                $ppn,
                $amountPaid,
                $amountOwed,
            ) {
                $entry = JournalEntry::query()->create([
                    'store_id' => $store->id,
                    'entry_date' => $entryDate,
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'description' => 'Pembelian '.$purchase->purchase_number,
                    'user_id' => $purchase->user_id,
                ]);

                if ($dpp > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $persediaan->id,
                        'debit' => $dpp,
                        'credit' => 0,
                        'memo' => 'Nilai perolehan (DPP persediaan)',
                    ]);
                }

                // Satu baris jurnal per biaya agar rincian pengeluaran tetap terbaca di laporan.
                foreach ($purchase->charges as $charge) {
                    $chargeAmount = round((float) $charge->amount, 2);
                    if ($chargeAmount <= 0) {
                        continue;
                    }

                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $beban->id,
                        'debit' => $chargeAmount,
                        'credit' => 0,
                        'memo' => $charge->name,
                    ]);
                }

                if ($ppn > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $ppnMasukan->id,
                        'debit' => $ppn,
                        'credit' => 0,
                        'memo' => 'PPN Masukan',
                    ]);
                }

                // Kredit: kas/bank untuk yang sudah dibayar
                if ($amountPaid > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $payingAccount->id,
                        'debit' => 0,
                        'credit' => $amountPaid,
                        'memo' => 'Pembayaran pembelian via '.$payingLabel,
                    ]);
                }

                // Kredit: utang usaha untuk sisa yang belum dibayar
                if ($amountOwed > 0) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $utangUsaha->id,
                        'debit' => 0,
                        'credit' => $amountOwed,
                        'memo' => 'Utang usaha (pembelian kredit/cicilan)',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('accounting.post_purchase_failed', [
                'purchase_id' => $purchase->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Posting koreksi stok ke jurnal.
     *
     * Selisih minus memunculkan beban kerugian persediaan (debit) atas
     * pengurangan persediaan (kredit). Selisih plus membalik arahnya sehingga
     * surplus mengurangi saldo beban tersebut. Selisih bersih nol tidak dijurnal.
     */
    public static function postStockAdjustment(Store $store, StockAdjustment $document): void
    {
        if (! self::moduleEnabled($store)) {
            return;
        }

        if (! $document->isPosted()) {
            return;
        }

        $net = round((float) $document->total_variance_value, 2);

        if (abs($net) < 0.01) {
            return;
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $exists = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', StockAdjustment::class)
            ->where('reference_id', $document->id)
            ->exists();

        if ($exists) {
            return;
        }

        $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);
        $kerugian = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_KERUGIAN_PERSEDIAAN);

        if (! $persediaan || ! $kerugian) {
            Log::warning('accounting.missing_coa', ['store_id' => $store->id]);

            return;
        }

        $amount = abs($net);
        $isLoss = $net < 0;

        try {
            DB::transaction(function () use (
                $store,
                $document,
                $persediaan,
                $kerugian,
                $amount,
                $isLoss
            ) {
                $prefix = $document->type === StockAdjustment::TYPE_OPNAME ? 'Stock opname ' : 'Penyesuaian stok ';

                $entry = JournalEntry::query()->create([
                    'store_id' => $store->id,
                    'entry_date' => $document->posted_at?->toDateString() ?? $document->created_at->toDateString(),
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $document->id,
                    'description' => $prefix.$document->adjustment_number,
                    'user_id' => $document->posted_by ?? $document->created_by,
                ]);

                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $isLoss ? $kerugian->id : $persediaan->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => $isLoss ? 'Kerugian persediaan (selisih minus)' : 'Surplus persediaan (selisih plus)',
                ]);

                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $isLoss ? $persediaan->id : $kerugian->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => $isLoss ? 'Pengurangan persediaan' : 'Penambahan persediaan',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('accounting.post_stock_adjustment_failed', [
                'document_id' => $document->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Posting kas masuk / keluar dari shift kasir ke jurnal.
     *
     * Pemetaan:
     *  - type=in, category=revenue   → Debit Kas, Kredit Pendapatan Lain-lain
     *  - type=in, category=non_revenue → Debit Kas, Kredit Modal (setoran murni)
     *  - type=out, category=expense   → Debit Beban Operasional, Kredit Kas
     *  - type=out, category=non_expense → Debit Modal, Kredit Kas (penarikan murni)
     */
    public static function postCashTransaction(Store $store, CashTransaction $ct): void
    {
        if (! self::moduleEnabled($store)) {
            return;
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $exists = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', CashTransaction::class)
            ->where('reference_id', $ct->id)
            ->exists();

        if ($exists) {
            return;
        }

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);
        $modal = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_MODAL);
        // Gunakan akun pendapatan tip sebagai pendapatan lain-lain sementara
        // karena bootstrap belum punya CODE_PENDAPATAN_LAIN.
        $pendapatanLain = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_TIP);

        if (! $kas || ! $beban || ! $modal || ! $pendapatanLain) {
            Log::warning('accounting.missing_coa_cash_trx', ['store_id' => $store->id]);

            return;
        }

        $amount = round((float) $ct->amount, 2);
        if ($amount < 0.01) {
            return;
        }

        $isIn = $ct->type === CashTransaction::TYPE_IN;

        // Tentukan pasangan akun debit/kredit
        if ($isIn && $ct->category === CashTransaction::CATEGORY_REVENUE) {
            $debitAccount = $kas;
            $creditAccount = $pendapatanLain;
            $memo = 'Pendapatan lain-lain: '.($ct->notes ?? $ct->party_name);
        } elseif ($isIn) {
            // non_revenue: setoran kas murni (modal masuk)
            $debitAccount = $kas;
            $creditAccount = $modal;
            $memo = 'Setoran kas: '.($ct->notes ?? $ct->party_name);
        } elseif ($ct->category === CashTransaction::CATEGORY_EXPENSE) {
            $debitAccount = $beban;
            $creditAccount = $kas;
            $memo = 'Beban operasional: '.($ct->notes ?? $ct->party_name);
        } else {
            // non_expense: penarikan kas murni
            $debitAccount = $modal;
            $creditAccount = $kas;
            $memo = 'Penarikan kas: '.($ct->notes ?? $ct->party_name);
        }

        $entryDate = $ct->created_at->toDateString();
        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        $entry = DB::transaction(function () use (
            $store, $ct, $entryDate, $debitAccount, $creditAccount, $amount, $memo
        ) {
            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $entryDate,
                'reference_type' => CashTransaction::class,
                'reference_id' => $ct->id,
                'description' => 'Kas '.($ct->type === CashTransaction::TYPE_IN ? 'masuk' : 'keluar').' #'.$ct->id,
                'user_id' => $ct->user_id,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $debitAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'memo' => $memo,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $creditAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'memo' => $memo,
            ]);

            $ct->update(['journal_entry_id' => $entry->id]);

            self::assertBalanced($entry);

            return $entry;
        });
    }

    /**
     * Hapus jurnal dokumen koreksi saat dibatalkan.
     *
     * Pembatalan memutar balik stok, sehingga entri jurnal yang pernah dibuat
     * ikut dihapus agar buku tidak mencatat dokumen yang sudah batal.
     */
    public static function reverseStockAdjustment(Store $store, StockAdjustment $document): void
    {
        // Tetap no-op jika modul mati: jangan hapus histori jurnal diam-diam.
        if (! self::moduleEnabled($store)) {
            return;
        }

        $entries = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', StockAdjustment::class)
            ->where('reference_id', $document->id)
            ->get();

        foreach ($entries as $entry) {
            AccountingPeriodGuard::assertDateOpen($store, $entry->entry_date);
        }

        $deletedIds = $entries->modelKeys();
        JournalEntry::query()->whereKey($deletedIds)->delete();

        ActivityLogger::log(
            store: $store->id,
            action: 'journal.cancelled',
            description: 'Membatalkan jurnal '.$document->adjustment_number,
            subject: $document,
            metadata: [
                'deleted_entry_ids' => $deletedIds,
                'count' => count($deletedIds),
            ],
        );
    }

    /**
     * Jurnal retur penjualan: membalik pengakuan pendapatan dan HPP
     * sebesar nilai yang diretur, pada periode berjalan (bukan periode lama).
     */
    public static function postSalesReturn(Store $store, SalesReturn $return): void
    {
        if (! self::moduleEnabled($store)) {
            return;
        }

        $totalRefund = (float) ($return->total_amount ?? 0);
        if ($totalRefund < 0.01) {
            return;
        }

        // Dedup: satu jurnal per dokumen retur.
        $exists = JournalEntry::query()
            ->where('store_id', $store->id)
            ->where('reference_type', SalesReturn::class)
            ->where('reference_id', $return->id)
            ->exists();
        if ($exists) {
            return;
        }

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
        $piutang = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA);
        $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);
        $pendapatan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PENDAPATAN_PENJUALAN);
        $ppnKeluaran = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_UTANG_PPN_KELUARAN);
        $hpp = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_HPP);

        if (! $pendapatan || ! $hpp || ! $persediaan) {
            return;
        }

        $entryDate = $return->returned_at->toDateString();
        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        // HPP balik: batch load semua TransactionItem transaksi asli sebelum loop.
        $sourceItems = TransactionItem::query()
            ->where('transaction_id', $return->transaction_id)
            ->get()
            ->keyBy(fn ($item) => $item->product_id.':'.($item->product_variant_id ?? 'null'));

        $costOfReturned = 0.0;
        foreach ($return->lines as $line) {
            $key = $line->product_id.':'.($line->product_variant_id ?? 'null');
            $sourceItem = $sourceItems->get($key);
            if ($sourceItem) {
                $costOfReturned += (float) $sourceItem->total_cost_snapshot * ((float) $line->quantity / max((float) $sourceItem->quantity, 0.0001));
            }
        }

        // Akun penerima dana retur: ikut metode pembayaran transaksi asli.
        $originalMethod = $return->transaction?->payment_method ?? 'cash';
        $refundMethod = $return->refund_method;
        $isCashRefund = $refundMethod === 'cash' || ($refundMethod === 'original' && $originalMethod === 'cash');
        $refundAccount = $isCashRefund ? $kas : ($bank ?? $kas);
        $refundAccountLabel = $isCashRefund ? 'Kas' : 'Bank';

        try {
            DB::transaction(function () use ($store, $return, $totalRefund, $costOfReturned, $refundAccount, $refundAccountLabel, $pendapatan, $hpp, $persediaan, $entryDate) {
                $entry = JournalEntry::query()->create([
                    'store_id' => $store->id,
                    'entry_date' => $entryDate,
                    'reference_type' => SalesReturn::class,
                    'reference_id' => $return->id,
                    'description' => 'Retur penjualan '.$return->return_number.' (transaksi '.($return->transaction?->transaction_number ?? '-').')',
                    'user_id' => $return->user_id,
                ]);

                $memo = 'Retur penjualan '.$return->return_number;

                // Debit: kurangi pendapatan (nilai retur).
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $pendapatan->id,
                    'debit' => $totalRefund,
                    'credit' => 0,
                    'memo' => $memo,
                ]);

                // Kredit: uang kembali ke pelanggan (kas/bank/piutang dikurangi).
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $refundAccount?->id,
                    'debit' => 0,
                    'credit' => $totalRefund,
                    'memo' => $memo.' — refund via '.$refundAccountLabel,
                ]);

                // Persediaan masuk kembali & HPP balik (sebesar biaya historis).
                if ($costOfReturned >= 0.01 && $persediaan && $hpp) {
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $persediaan->id,
                        'debit' => $costOfReturned,
                        'credit' => 0,
                        'memo' => $memo.' — persediaan masuk',
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'chart_of_account_id' => $hpp->id,
                        'debit' => 0,
                        'credit' => $costOfReturned,
                        'memo' => $memo.' — HPP balik',
                    ]);
                }

                self::assertBalanced($entry);
            });
        } catch (\Throwable $e) {
            Log::error('accounting.post_sales_return_failed', [
                'sales_return_id' => $return->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Jurnal pembalik resmi: membalik debit/kredit entri lama ke periode terbuka.
     * Dipakai untuk mengoreksi kesalahan pada periode yang sudah ditutup tanpa
     * mengubah jurnal historis.
     */
    /**
     * Jurnal pembayaran gaji satu periode (dipanggil saat periode ditandai dibayar).
     *
     *   Dr Beban gaji & upah          = bruto − potongan lain (kasbon, denda telat, dll.)
     *   Dr Beban iuran BPJS perusahaan = iuran BPJS bagian perusahaan
     *   Cr Utang PPh 21 karyawan       = PPh 21 dipotong − kelebihan potong yang dikembalikan
     *   Cr Utang iuran BPJS            = iuran BPJS karyawan + perusahaan
     *   Cr Kas / Bank                  = total gaji bersih dibayarkan
     *
     * Potongan lain dihitung sebagai sisa (bruto − PPh − BPJS karyawan + refund − bersih)
     * agar jurnal selalu seimbang, termasuk saat gaji bersih dibatasi Rp0.
     */
    public static function postPayroll(
        Store $store,
        \App\Modules\Payroll\Models\PayrollPeriod $period,
        string $paymentMethod,
        string $entryDate,
        ?int $userId = null,
    ): ?JournalEntry {
        if (! self::moduleEnabled($store)) {
            return null;
        }

        AccountingPeriodGuard::assertDateOpen($store, $entryDate, 'payment_date');
        ChartOfAccountsBootstrap::ensureForStore($store);

        $code = static fn (string $c) => ChartOfAccountsBootstrap::account($store, $c)
            ?? throw new \RuntimeException("Akun {$c} tidak tersedia.");

        $kasBank = $code($paymentMethod === 'bank' ? ChartOfAccountsBootstrap::CODE_BANK : ChartOfAccountsBootstrap::CODE_KAS);
        $bebanGaji = $code(ChartOfAccountsBootstrap::CODE_BEBAN_GAJI);
        $bebanBpjs = $code(ChartOfAccountsBootstrap::CODE_BEBAN_BPJS);
        $utangPph = $code(ChartOfAccountsBootstrap::CODE_UTANG_PPH21);
        $utangBpjs = $code(ChartOfAccountsBootstrap::CODE_UTANG_BPJS);

        $salaryExpense = 0.0;
        $employerBpjs = 0.0;
        $employeeBpjs = 0.0;
        $pph = 0.0;
        $net = 0.0;

        foreach ($period->runs()->get() as $run) {
            $b = $run->component_breakdown ?? [];
            $bpjs = $b['bpjs'] ?? [];
            $runEmployer = (float) ($bpjs['health']['employer'] ?? 0) + (float) ($bpjs['jht']['employer'] ?? 0)
                + (float) ($bpjs['jp']['employer'] ?? 0) + (float) ($bpjs['jkk'] ?? 0) + (float) ($bpjs['jkm'] ?? 0);
            $runEmployee = (float) ($bpjs['total_employee'] ?? 0);
            $runPph = (float) ($b['tax']['monthly_tax'] ?? 0) - (float) ($b['tax']['refund'] ?? 0);
            $runNet = (float) $run->net_salary;
            $gross = (float) $run->gross_salary;
            $otherDeductions = $gross - $runPph - $runEmployee - $runNet;

            $salaryExpense += $gross - $otherDeductions;
            $employerBpjs += $runEmployer;
            $employeeBpjs += $runEmployee;
            $pph += $runPph;
            $net += $runNet;
        }

        $lines = array_filter([
            [$bebanGaji, round($salaryExpense, 2), 0, 'Gaji & upah '.$period->name],
            [$bebanBpjs, round($employerBpjs, 2), 0, 'Iuran BPJS bagian perusahaan'],
            $pph >= 0
                ? [$utangPph, 0, round($pph, 2), 'PPh 21 dipotong dari gaji']
                : [$utangPph, round(-$pph, 2), 0, 'Pengembalian kelebihan PPh 21'],
            [$utangBpjs, 0, round($employeeBpjs + $employerBpjs, 2), 'Iuran BPJS karyawan + perusahaan'],
            [$kasBank, 0, round($net, 2), 'Pembayaran gaji bersih'],
        ], fn ($line) => $line[1] > 0 || $line[2] > 0);

        if ($lines === []) {
            return null;
        }

        return DB::transaction(function () use ($store, $period, $entryDate, $userId, $lines) {
            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $entryDate,
                'reference_type' => 'payroll_period',
                'reference_id' => $period->id,
                'description' => 'Pembayaran gaji '.$period->name,
                'user_id' => $userId,
            ]);

            foreach ($lines as [$account, $debit, $credit, $memo]) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $account->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $memo,
                ]);
            }

            self::assertBalanced($entry);

            return $entry;
        });
    }

    public static function postReversal(Store $store, JournalEntry $original, string $entryDate, ?int $userId = null, ?string $note = null): ?JournalEntry
    {
        if (! self::moduleEnabled($store)) {
            return null;
        }

        if ((int) $original->store_id !== (int) $store->id) {
            throw new \RuntimeException('Entri jurnal bukan milik toko Anda.');
        }

        if ($original->reference_type === 'journal_reversal') {
            throw new \RuntimeException('Jurnal pembalik tidak dapat dibalik lagi.');
        }

        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        $lines = $original->lines()->get();
        if ($lines->isEmpty()) {
            throw new \RuntimeException('Entri jurnal tidak memiliki baris.');
        }

        return DB::transaction(function () use ($store, $original, $lines, $entryDate, $userId, $note) {
            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $entryDate,
                'reference_type' => 'journal_reversal',
                'reference_id' => $original->id,
                'description' => 'Jurnal pembalik '.$original->description.($note ? ' — '.$note : ''),
                'user_id' => $userId,
            ]);

            foreach ($lines as $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => 'Pembalik: '.$line->memo,
                ]);
            }

            self::assertBalanced($entry);

            ActivityLogger::log(
                store: $store->id,
                action: 'journal.reversed',
                description: 'Membuat jurnal pembalik untuk entri #'.$original->id.': '.$original->description,
                subject: $original,
                metadata: [
                    'original_entry_id' => $original->id,
                    'reversal_entry_id' => $entry->id,
                    'note' => $note,
                ],
                user: $userId,
            );

            return $entry;
        });
    }

    /**
     * Lempar exception jika total debit entri != total kredit.
     * Dipanggil di dalam DB::transaction sebelum commit.
     */
    private static function assertBalanced(JournalEntry $entry): void
    {
        $lines = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->selectRaw('COALESCE(SUM(debit),0) as total_debit, COALESCE(SUM(credit),0) as total_credit')
            ->first();

        $totalDebit = round((float) $lines->total_debit, 2);
        $totalCredit = round((float) $lines->total_credit, 2);

        if (abs($totalDebit - $totalCredit) > 0.02) {
            throw new \RuntimeException(
                "Jurnal tidak seimbang (debit={$totalDebit}, kredit={$totalCredit}) pada entry #{$entry->id}."
            );
        }
    }

    /**
     * Posting penerimaan pelunasan piutang.
     * Jurnal: Debit Kas/Bank, Kredit Piutang Usaha.
     * Tidak memposting pendapatan, PPN, HPP, atau persediaan kembali.
     */
    public static function postReceivableCollection(
        Store $store,
        \App\Models\ReceivablePayment $payment,
    ): ?JournalEntry {
        if (! self::moduleEnabled($store)) {
            return null;
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
        $piutangUsaha = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA);

        if (! $kas || ! $bank || ! $piutangUsaha) {
            Log::warning('accounting.missing_coa_receivable', ['store_id' => $store->id]);

            return null;
        }

        $entryDate = $payment->received_at->toDateString();
        AccountingPeriodGuard::assertDateOpen($store, $entryDate);

        $receivingAccount = $payment->payment_method === 'cash' ? $kas : $bank;
        $receivingLabel = $payment->payment_method === 'cash' ? 'Kas' : 'Bank';
        $amount = round((float) $payment->amount, 2);

        return DB::transaction(function () use (
            $store, $payment, $entryDate,
            $receivingAccount, $receivingLabel, $piutangUsaha, $amount,
        ) {
            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $entryDate,
                'reference_type' => \App\Models\ReceivablePayment::class,
                'reference_id' => $payment->id,
                'description' => 'Pelunasan piutang #'.$payment->transaction->transaction_number,
                'user_id' => $payment->user_id,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $receivingAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'memo' => $receivingLabel.' penerimaan pelunasan ('.$payment->payment_method.')',
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $piutangUsaha->id,
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Pelunasan piutang usaha',
            ]);

            // Simpan referensi journal ke receivable_payment
            $payment->update(['journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    private static function lineCogs(TransactionItem $item): float
    {
        $product = $item->product;
        if (! $product) {
            return 0.0;
        }

        $variant = $item->productVariant;
        $unitCost = null;
        if ($variant && $variant->cost_price !== null) {
            $unitCost = (float) $variant->cost_price;
        } elseif ($product->cost_price !== null) {
            $unitCost = (float) $product->cost_price;
        } else {
            $unitCost = 0.0;
        }

        return round($unitCost * (float) $item->quantity, 2);
    }
}
