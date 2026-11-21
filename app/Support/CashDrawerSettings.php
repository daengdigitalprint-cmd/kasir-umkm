<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\Store;
use Illuminate\Support\Facades\Hash;

/**
 * Pengaturan Manajemen Laci Kasir per toko (disimpan di settings toko):
 * - cash_out_approval_limit: uang keluar di atas nilai ini butuh PIN persetujuan (0 = mati)
 * - cash_approval_pin_hash: hash PIN persetujuan (tidak pernah dikirim ke browser)
 * - cash_quick_presets: tombol cepat transaksi yang sering dipakai
 */
class CashDrawerSettings
{
    public const MAX_PRESETS = 12;

    /**
     * @return array<int, array{type: string, category: string, label: string, notes: string, amount: ?int}>
     */
    public static function defaultPresets(): array
    {
        return [
            ['type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_NON_REVENUE, 'label' => 'Tambah modal kembalian', 'notes' => 'Tambah modal kembalian', 'amount' => null],
            ['type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_REVENUE, 'label' => 'Jual kardus bekas', 'notes' => 'Penjualan kardus bekas', 'amount' => null],
            ['type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE, 'label' => 'Beli galon', 'notes' => 'Beli air galon', 'amount' => null],
            ['type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE, 'label' => 'Bayar parkir', 'notes' => 'Bayar parkir', 'amount' => null],
            ['type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE, 'label' => 'Beli es batu', 'notes' => 'Beli es batu', 'amount' => null],
            ['type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_NON_EXPENSE, 'label' => 'Setor ke owner', 'notes' => 'Setoran kas ke owner', 'amount' => null],
        ];
    }

    /**
     * @return array<int, array{type: string, category: string, label: string, notes: string, amount: ?int}>
     */
    public static function presets(Store $store): array
    {
        $saved = $store->settings['cash_quick_presets'] ?? null;

        return is_array($saved) ? array_values($saved) : self::defaultPresets();
    }

    public static function approvalLimit(Store $store): int
    {
        return max(0, (int) ($store->settings['cash_out_approval_limit'] ?? 0));
    }

    public static function hasApprovalPin(Store $store): bool
    {
        return ! empty($store->settings['cash_approval_pin_hash'] ?? null);
    }

    public static function verifyApprovalPin(Store $store, ?string $pin): bool
    {
        $hash = $store->settings['cash_approval_pin_hash'] ?? null;

        return $hash && $pin !== null && $pin !== '' && Hash::check($pin, $hash);
    }

    /**
     * Periksa PIN persetujuan dengan batas percobaan (5x, kunci 5 menit) per toko & pengguna.
     * Mengembalikan pesan kesalahan, atau null bila PIN benar.
     */
    public static function checkApprovalPin(Store $store, \App\Models\User $user, ?string $pin): ?string
    {
        $key = 'cash-approval-pin:'.$store->id.':'.$user->id;

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            return 'Terlalu banyak percobaan PIN. Coba lagi dalam '.\Illuminate\Support\Facades\RateLimiter::availableIn($key).' detik.';
        }

        if (! self::verifyApprovalPin($store, $pin)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);

            return $pin ? 'PIN persetujuan salah.' : 'Masukkan PIN persetujuan dari owner/supervisor.';
        }

        \Illuminate\Support\Facades\RateLimiter::clear($key);

        return null;
    }

    /**
     * Pembatalan (void) penjualan oleh kasir perlu PIN persetujuan?
     * Bawaan NONAKTIF supaya toko lama tidak terpengaruh; pemilik menyalakannya
     * sendiri di Kas Masuk/Keluar → Pengaturan.
     */
    public static function voidRequiresApproval(Store $store): bool
    {
        return (bool) ($store->settings['cashier_void_requires_approval'] ?? false);
    }

    /**
     * Batas diskon manual per baris (persen dari subtotal baris) yang boleh diberi kasir tanpa PIN.
     * Di atas batas ini kasir perlu PIN persetujuan. Bawaan NONAKTIF (null = tanpa batas)
     * supaya toko lama tidak terpengaruh; pemilik mengisinya di Kas Masuk/Keluar → Pengaturan.
     */
    public static function cashierDiscountLimit(Store $store): ?int
    {
        $raw = $store->settings['cashier_discount_limit_percent'] ?? null;

        return ($raw === null || $raw === '') ? null : max(0, min(100, (int) $raw));
    }

    /**
     * Uang keluar senilai ini butuh PIN persetujuan? Hanya berlaku bila batas & PIN sudah diatur.
     */
    public static function outNeedsApproval(Store $store, float $amount): bool
    {
        $limit = self::approvalLimit($store);

        return $limit > 0 && self::hasApprovalPin($store) && $amount > $limit;
    }

    /**
     * Data untuk halaman (tanpa hash PIN).
     *
     * @return array<string, mixed>
     */
    public static function forPage(Store $store): array
    {
        return [
            'approval_limit' => self::approvalLimit($store),
            'has_approval_pin' => self::hasApprovalPin($store),
            'cashier_discount_limit' => self::cashierDiscountLimit($store),
            'void_requires_approval' => self::voidRequiresApproval($store),
            'presets' => self::presets($store),
        ];
    }
}
