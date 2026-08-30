<?php

namespace App\Support\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Store;

/**
 * Bagan akun standar UMKM ritel (sederhana) — siap untuk jurnal penjualan/pembelian & PPN.
 */
class ChartOfAccountsBootstrap
{
    public const CODE_KAS = '1.1.01';

    public const CODE_BANK = '1.1.02';

    public const CODE_PIUTANG_USAHA = '1.3.01';

    public const CODE_PERSEDIAAN = '1.2.01';

    public const CODE_PPN_MASUKAN = '1.2.02';

    public const CODE_ASET_TETAP = '1.4.01';

    public const CODE_AKUMULASI_PENYUSUTAN = '1.4.02';

    public const CODE_UTANG_PPN_KELUARAN = '2.1.01';

    public const CODE_UTANG_USAHA = '2.1.02';

    public const CODE_UTANG_PAJAK = '2.1.03';

    public const CODE_MODAL = '3.1.01';

    public const CODE_SALDO_LABA = '3.2.01';

    public const CODE_PENDAPATAN_PENJUALAN = '4.1.01';

    public const CODE_PENDAPATAN_TIP = '4.9.01';

    public const CODE_HPP = '5.1.01';

    public const CODE_BEBAN_OPERASIONAL = '6.1.01';

    public const CODE_BEBAN_KERUGIAN_PERSEDIAAN = '6.1.02';

    public const CODE_BEBAN_PAJAK = '6.1.03';

    public static function ensureForStore(Store $store): void
    {
        $defs = [
            [self::CODE_KAS, 'Kas', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_CURRENT_ASSET, 10],
            [self::CODE_BANK, 'Bank (non-tunai)', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_CURRENT_ASSET, 15],
            [self::CODE_PIUTANG_USAHA, 'Piutang usaha', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_CURRENT_ASSET, 25],
            [self::CODE_PERSEDIAAN, 'Persediaan barang dagangan', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_CURRENT_ASSET, 30],
            [self::CODE_PPN_MASUKAN, 'PPN Masukan (kredit pajak)', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_CURRENT_ASSET, 40],
            [self::CODE_ASET_TETAP, 'Aset tetap', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_NON_CURRENT_ASSET, 42],
            [self::CODE_AKUMULASI_PENYUSUTAN, 'Akumulasi penyusutan aset tetap', ChartOfAccount::TYPE_ASSET, ChartOfAccount::GROUP_NON_CURRENT_ASSET, 43],
            [self::CODE_UTANG_PPN_KELUARAN, 'Utang PPN Keluaran', ChartOfAccount::TYPE_LIABILITY, ChartOfAccount::GROUP_CURRENT_LIABILITY, 50],
            [self::CODE_UTANG_USAHA, 'Utang usaha', ChartOfAccount::TYPE_LIABILITY, ChartOfAccount::GROUP_CURRENT_LIABILITY, 55],
            [self::CODE_UTANG_PAJAK, 'Utang pajak penghasilan', ChartOfAccount::TYPE_LIABILITY, ChartOfAccount::GROUP_CURRENT_LIABILITY, 56],
            [self::CODE_MODAL, 'Modal disetor', ChartOfAccount::TYPE_EQUITY, ChartOfAccount::GROUP_EQUITY, 60],
            [self::CODE_SALDO_LABA, 'Saldo laba', ChartOfAccount::TYPE_EQUITY, ChartOfAccount::GROUP_EQUITY, 65],
            [self::CODE_PENDAPATAN_PENJUALAN, 'Pendapatan penjualan (DPP)', ChartOfAccount::TYPE_REVENUE, null, 70],
            [self::CODE_PENDAPATAN_TIP, 'Pendapatan lain-lain (tip)', ChartOfAccount::TYPE_REVENUE, null, 80],
            [self::CODE_HPP, 'Harga pokok penjualan (HPP)', ChartOfAccount::TYPE_COGS, null, 90],
            [self::CODE_BEBAN_OPERASIONAL, 'Beban operasional', ChartOfAccount::TYPE_EXPENSE, null, 100],
            [self::CODE_BEBAN_KERUGIAN_PERSEDIAAN, 'Kerugian selisih persediaan', ChartOfAccount::TYPE_EXPENSE, null, 105],
            [self::CODE_BEBAN_PAJAK, 'Beban pajak penghasilan', ChartOfAccount::TYPE_EXPENSE, null, 106],
        ];

        foreach ($defs as [$code, $name, $type, $group, $sort]) {
            ChartOfAccount::query()->firstOrCreate(
                ['store_id' => $store->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'balance_sheet_group' => $group,
                    'sort_order' => $sort,
                    'is_system' => true,
                ],
            );
        }

        // Migrasi label lama akun modal untuk toko yang sudah ada.
        ChartOfAccount::query()
            ->where('store_id', $store->id)
            ->where('code', self::CODE_MODAL)
            ->where('name', 'Modal pemilik')
            ->update(['name' => 'Modal disetor']);
    }

    public static function account(Store $store, string $code): ?ChartOfAccount
    {
        self::ensureForStore($store);

        return ChartOfAccount::query()
            ->where('store_id', $store->id)
            ->where('code', $code)
            ->first();
    }
}
