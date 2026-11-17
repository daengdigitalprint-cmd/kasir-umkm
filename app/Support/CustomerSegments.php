<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * F9 — Segmentasi pelanggan, dihitung dari transaksi selesai (tanpa tabel baru).
 *
 * Satu "kunjungan" = satu hari yang berisi transaksi pelanggan itu (dua struk di
 * hari yang sama dihitung sekali). Tiap pelanggan masuk paling banyak satu segmen,
 * dengan urutan prioritas Setia → Baru → Lama tidak datang.
 */
final class CustomerSegments
{
    public const LOYAL = 'setia';

    public const NEW = 'baru';

    public const LAPSED = 'lama';

    public const LABELS = [
        self::LOYAL => 'Setia',
        self::NEW => 'Baru',
        self::LAPSED => 'Lama tidak datang',
    ];

    public const DEFAULTS = [
        'loyal_visits' => 4,
        'loyal_days' => 30,
        'new_days' => 30,
        'lapsed_min_visits' => 3,
        'lapsed_days' => 30,
        'messages' => [
            self::LOYAL => 'Halo {nama}, terima kasih sudah jadi pelanggan setia {toko}! Kunjungan berikutnya ada kejutan kecil untuk Anda.',
            self::NEW => 'Halo {nama}, terima kasih sudah mampir ke {toko}. Semoga cocok, ditunggu kunjungan berikutnya ya!',
            self::LAPSED => 'Halo {nama}, sudah lama tidak mampir ke {toko}. Kami kangen! Ada menu baru yang bisa dicoba.',
        ],
    ];

    /** Pengaturan toko digabung dengan bawaan. */
    public static function settings(Store $store): array
    {
        $saved = (array) ($store->settings['customer_segments'] ?? []);
        $settings = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $settings['messages'] = array_merge(self::DEFAULTS['messages'], (array) ($saved['messages'] ?? []));

        return $settings;
    }

    /**
     * Ringkasan per pelanggan: total hari kunjungan, kunjungan dalam rentang "Setia",
     * kunjungan pertama & terakhir, total belanja.
     */
    public static function statsQuery(Store $store, array $settings): QueryBuilder
    {
        $loyalSince = now()->subDays((int) $settings['loyal_days'])->format('Y-m-d H:i:s');

        return DB::table('transactions')
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->where('is_void', false)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as visits')
            ->selectRaw('COUNT(DISTINCT CASE WHEN created_at >= ? THEN DATE(created_at) END) as recent_visits', [$loyalSince])
            ->selectRaw('MIN(created_at) as first_visit')
            ->selectRaw('MAX(created_at) as last_visit')
            ->selectRaw('SUM(total_amount) as spent');
    }

    /** Pelanggan toko + kolom statistik (customer_stats.*). */
    public static function customersWithStats(Store $store, array $settings): Builder
    {
        return \App\Models\Customer::query()
            ->where('customers.store_id', $store->id)
            ->joinSub(self::statsQuery($store, $settings), 'customer_stats', 'customer_stats.customer_id', '=', 'customers.id')
            ->select('customers.*', 'customer_stats.visits', 'customer_stats.recent_visits', 'customer_stats.first_visit', 'customer_stats.last_visit', 'customer_stats.spent');
    }

    public static function applySegment(Builder $query, string $segment, array $settings): Builder
    {
        $loyal = (int) $settings['loyal_visits'];
        $newSince = now()->subDays((int) $settings['new_days'])->format('Y-m-d H:i:s');
        $lapsedBefore = now()->subDays((int) $settings['lapsed_days'])->format('Y-m-d H:i:s');

        return match ($segment) {
            self::LOYAL => $query->where('customer_stats.recent_visits', '>=', $loyal),
            self::NEW => $query->where('customer_stats.recent_visits', '<', $loyal)
                ->where('customer_stats.first_visit', '>=', $newSince),
            self::LAPSED => $query->where('customer_stats.recent_visits', '<', $loyal)
                ->where('customer_stats.first_visit', '<', $newSince)
                ->where('customer_stats.visits', '>=', (int) $settings['lapsed_min_visits'])
                ->where('customer_stats.last_visit', '<', $lapsedBefore),
            default => $query,
        };
    }

    /** Segmen satu baris hasil customersWithStats (null = tidak masuk segmen mana pun). */
    public static function segmentOf(object $row, array $settings): ?string
    {
        $newSince = now()->subDays((int) $settings['new_days']);
        $lapsedBefore = now()->subDays((int) $settings['lapsed_days']);
        $first = \Illuminate\Support\Carbon::parse($row->first_visit);
        $last = \Illuminate\Support\Carbon::parse($row->last_visit);

        if ((int) $row->recent_visits >= (int) $settings['loyal_visits']) {
            return self::LOYAL;
        }
        if ($first->gte($newSince)) {
            return self::NEW;
        }
        if ((int) $row->visits >= (int) $settings['lapsed_min_visits'] && $last->lt($lapsedBefore)) {
            return self::LAPSED;
        }

        return null;
    }

    /**
     * Nomor untuk tautan wa.me (format internasional tanpa +). 08xx → 628xx, 8xx → 628xx.
     * Null bila nomor terlalu pendek untuk nomor HP.
     */
    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '' || strlen($digits) < 9) {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}
