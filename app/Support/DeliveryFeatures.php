<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;

/** F10 — Modul Pesanan Antar mandiri (kurir sendiri). */
final class DeliveryFeatures
{
    /** Penanda `payment_details.source` untuk pesanan yang dibuat pelanggan lewat tautan pesan antar. */
    public const SELF_ORDER_SOURCE = 'pesan_antar';

    /** Batas daftar nama kurir per toko dan panjang tiap nama. */
    public const MAX_COURIER_NAMES = 30;

    public const MAX_COURIER_NAME_LENGTH = 60;

    public static function enabled(?Store $store): bool
    {
        return PlanModules::settingOn($store, 'delivery_enabled');
    }

    /**
     * Pemesanan mandiri lewat tautan toko (/antar/{slug}): saklar turunan modul Pesanan Antar.
     * Mati = tautan menampilkan "belum tersedia" dan pesanan baru ditolak.
     */
    public static function selfOrderEnabled(?Store $store): bool
    {
        return $store !== null
            && self::enabled($store)
            && (bool) ($store->settings['delivery_self_order_enabled'] ?? false);
    }

    /** Tautan pesan antar untuk dibagikan ke pelanggan. */
    public static function publicUrl(Store $store): string
    {
        return url('/antar/'.$store->slug);
    }

    public static function isSelfOrder(?Transaction $transaction): bool
    {
        return $transaction !== null
            && ($transaction->payment_details['source'] ?? null) === self::SELF_ORDER_SOURCE;
    }

    /** Pesanan dari tautan yang menunggu diterima/ditolak kasir. */
    public static function pendingSelfOrderCount(Store $store): int
    {
        return DeliveryOrder::query()
            ->where('store_id', $store->id)
            ->where('status', DeliveryOrder::STATUS_PENDING)
            ->count();
    }

    public static function defaultFee(Store $store): float
    {
        return max(0.0, (float) ($store->settings['delivery_default_fee'] ?? 0));
    }

    /**
     * Nama-nama kurir toko (diisi di pengaturan Pengantaran). Kurir TIDAK perlu punya akun:
     * cukup namanya. Kosong = pengantaran hanya diberi keterangan "Kurir toko".
     *
     * @return list<string>
     */
    public static function courierNames(Store $store): array
    {
        return self::normalizeCourierNames($store->settings['delivery_courier_names'] ?? []);
    }

    /**
     * Rapikan daftar nama kurir: buang kosong, samakan spasi, hilangkan duplikat
     * (tanpa membedakan huruf besar/kecil), batasi jumlah dan panjangnya.
     *
     * @return list<string>
     */
    public static function normalizeCourierNames(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $value) {
            $name = self::cleanCourierName(is_scalar($value) ? (string) $value : null, self::MAX_COURIER_NAME_LENGTH);
            if ($name === null) {
                continue;
            }
            $names[mb_strtolower($name)] ??= $name;
            if (count($names) >= self::MAX_COURIER_NAMES) {
                break;
            }
        }

        return array_values($names);
    }

    /** Nama kurir untuk satu pengantaran: teks bebas yang dirapikan, atau null bila kosong. */
    public static function cleanCourierName(?string $raw, int $max = 120): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));

        return $name === '' ? null : mb_substr($name, 0, $max);
    }

    /**
     * Kasir versi lama (atau penjualan offline yang tertunda) masih mengirim ID pengguna sebagai
     * kurir. Ubah jadi namanya bila ia memang karyawan toko ini; selain itu abaikan.
     */
    public static function legacyCourierName(Store $store, mixed $userId): ?string
    {
        if (! is_numeric($userId) || (int) $userId <= 0) {
            return null;
        }

        $name = User::query()
            ->whereKey((int) $userId)
            ->where(fn ($q) => $q->where('store_id', $store->id)
                ->orWhereIn('id', fn ($sub) => $sub->select('user_id')->from('store_user')->where('store_id', $store->id)))
            ->value('name');

        return self::cleanCourierName($name);
    }
}
