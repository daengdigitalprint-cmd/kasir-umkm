<?php

/**
 * Reset data PENJUALAN (transaksi) SATU akun berdasarkan email.
 * Hanya menghapus transaksi + turunan langsungnya (item, alokasi konsumsi,
 * jurnal akuntansi). Produk, stok, pelanggan, pengaturan TIDAK disentuh.
 *
 * Catatan: stok TIDAK dikembalikan. Ini murni hapus riwayat penjualan.
 *
 * Cara pakai:
 *   php scripts/reset_sales_account.php <email>                      # dry-run: lihat jumlah
 *   php scripts/reset_sales_account.php <email> --execute            # eksekusi hapus penjualan
 *   php scripts/reset_sales_account.php <email> --execute --productions # sekalian hapus produksi
 *
 * Contoh:
 *   php scripts/reset_sales_account.php outletberkahf3@gmail.com
 *   php scripts/reset_sales_account.php outletberkahf3@gmail.com --execute --productions
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$email = $argv[1] ?? null;
$execute = in_array('--execute', $argv, true);
$deleteProductions = in_array('--productions', $argv, true);
$deleteCashShifts = in_array('--cash-shifts', $argv, true);

if (! $email) {
    fwrite(STDERR, "Penggunaan: php scripts/reset_sales_account.php <email> [--execute]\n");
    exit(1);
}

$user = DB::table('users')->where('email', $email)->first();
if (! $user) {
    fwrite(STDERR, "User dengan email '$email' tidak ditemukan.\n");
    exit(1);
}

$storeIds = DB::table('store_user')->where('user_id', $user->id)->pluck('store_id')->all();
if ($user->store_id && ! in_array((int) $user->store_id, $storeIds, true)) {
    $storeIds[] = (int) $user->store_id;
}
$storeIds = array_values(array_unique(array_map('intval', $storeIds)));

if (! $storeIds) {
    fwrite(STDERR, "User '$email' tidak memiliki store.\n");
    exit(1);
}

$transactionIds = DB::table('transactions')->whereIn('store_id', $storeIds)->pluck('id')->all();

$counts = [
    'transactions' => count($transactionIds),
    'transaction_items' => 0,
    'sale_consumption_allocations' => 0,
    'journal_entries' => 0,
    'journal_lines' => 0,
];

if ($transactionIds) {
    $itemIds = DB::table('transaction_items')->whereIn('transaction_id', $transactionIds)->pluck('id')->all();
    $counts['transaction_items'] = count($itemIds);
    $counts['sale_consumption_allocations'] = $itemIds
        ? DB::table('sale_consumption_allocations')->whereIn('transaction_item_id', $itemIds)->count()
        : 0;

    $journalEntryIds = DB::table('journal_entries')
        ->whereIn('store_id', $storeIds)
        ->where('reference_type', \App\Models\Transaction::class)
        ->whereIn('reference_id', $transactionIds)
        ->pluck('id')->all();
    $counts['journal_entries'] = count($journalEntryIds);
    $counts['journal_lines'] = $journalEntryIds
        ? DB::table('journal_lines')->whereIn('journal_entry_id', $journalEntryIds)->count()
        : 0;
}

if ($deleteProductions) {
    $batchIds = DB::table('production_batches')->whereIn('store_id', $storeIds)->pluck('id')->all();
    $counts['production_batches'] = count($batchIds);
    $counts['production_batch_lines'] = $batchIds
        ? DB::table('production_batch_lines')->whereIn('production_batch_id', $batchIds)->count()
        : 0;
}

if ($deleteCashShifts) {
    $counts['cash_shifts'] = DB::table('cash_shifts')->whereIn('store_id', $storeIds)->count();
    $counts['cash_transactions'] = DB::table('cash_transactions')->whereIn('store_id', $storeIds)->count();
}

echo "Akun  : $email (user_id={$user->id})\n";
echo 'Store : '.implode(', ', $storeIds)."\n";
echo 'Mode  : '.($execute ? 'EXECUTE' : 'DRY-RUN')."\n\n";
foreach ($counts as $table => $n) {
    echo sprintf("  %-28s %d\n", $table, $n);
}
echo "\n";

$total = array_sum($counts);
if ($total === 0) {
    echo "Tidak ada data untuk dihapus pada akun ini.\n";
    exit(0);
}

if (! $execute) {
    echo "Dry-run selesai. Tambahkan --execute untuk benar-benar menghapus.\n";
    exit(0);
}

DB::transaction(function () use ($storeIds, $transactionIds, $deleteProductions, $deleteCashShifts) {
    // Urutan: anak -> induk, supaya tidak melanggar foreign key apa pun.
    DB::table('journal_lines')
        ->whereIn('journal_entry_id', function ($q) use ($storeIds, $transactionIds) {
            $q->select('id')->from('journal_entries')
                ->whereIn('store_id', $storeIds)
                ->where('reference_type', \App\Models\Transaction::class)
                ->whereIn('reference_id', $transactionIds);
        })
        ->delete();

    DB::table('journal_entries')
        ->whereIn('store_id', $storeIds)
        ->where('reference_type', \App\Models\Transaction::class)
        ->whereIn('reference_id', $transactionIds)
        ->delete();

    DB::table('sale_consumption_allocations')
        ->whereIn('transaction_item_id', function ($q) use ($transactionIds) {
            $q->select('id')->from('transaction_items')->whereIn('transaction_id', $transactionIds);
        })
        ->delete();

    DB::table('transaction_items')->whereIn('transaction_id', $transactionIds)->delete();
    DB::table('transactions')->whereIn('store_id', $storeIds)->delete();

    if ($deleteProductions) {
        DB::table('production_batch_lines')
            ->whereIn('production_batch_id', function ($q) use ($storeIds) {
                $q->select('id')->from('production_batches')->whereIn('store_id', $storeIds);
            })
            ->delete();

        DB::table('production_batches')->whereIn('store_id', $storeIds)->delete();
    }

    if ($deleteCashShifts) {
        DB::table('cash_transactions')->whereIn('store_id', $storeIds)->delete();
        DB::table('cash_shifts')->whereIn('store_id', $storeIds)->delete();
    }
});

echo "Selesai. Total baris dihapus: $total\n";
echo "Stok, produk, pelanggan, dan pengaturan TIDAK berubah.\n";
