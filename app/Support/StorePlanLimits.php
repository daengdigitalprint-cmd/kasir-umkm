<?php

namespace App\Support;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use Illuminate\Validation\ValidationException;

final class StorePlanLimits
{
    /** Batas produk paket Starter / Starter (tahunan); selaraskan `subscription_plans.max_products` di seeder. */
    public const STARTER_MAX_PRODUCTS = 30;

    /**
     * Daftar fitur paket Gratis yang ditampilkan ke pengguna (halaman Langganan, seeder,
     * data bawaan mobile). Harus sama dengan aturan di kode: batas di subscription_plans,
     * gerbang `allowsAdvancedRetailFeatures`, dan modul berbayar di PlanModules.
     */
    public const STARTER_FEATURES = [
        'Hingga 30 produk',
        'Hingga 50 pelanggan',
        'Transaksi tanpa batas',
        '1 outlet',
        'Kasir (POS) & cetak struk',
        'Stok dasar & riwayat stok',
        'Laporan penjualan',
    ];

    /**
     * Paket efektif untuk toko (trial / setelah pembayaran tercatat di kolom ini).
     */
    public static function effectivePlan(Store $store): ?SubscriptionPlan
    {
        if (! $store->relationLoaded('subscriptionPlan')) {
            $store->load('subscriptionPlan');
        }

        return $store->subscriptionPlan;
    }

    /**
     * Paket untuk UI / limit: pakai FK toko; jika kosong tapi langganan berjangka (ada subscription_end),
     * fallback ke paket Starter di database (toko lama / data belum ter-backfill).
     */
    public static function planForDisplay(Store $store): ?SubscriptionPlan
    {
        $plan = self::effectivePlan($store);
        if ($plan !== null) {
            return $plan;
        }
        if (! $store->isSubscriptionActive() || $store->subscription_end === null) {
            return null;
        }

        return SubscriptionPlan::query()
            ->where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();
    }

    /** null = tidak dibatasi (paket tanpa limit atau toko legacy tanpa plan). */
    public static function maxProducts(Store $store): ?int
    {
        if (! $store->isSubscriptionActive()) {
            return null;
        }
        $plan = self::effectivePlan($store) ?? self::planForDisplay($store);

        return $plan?->max_products;
    }

    public static function maxTransactions(Store $store): ?int
    {
        if (! $store->isSubscriptionActive()) {
            return null;
        }
        $plan = self::effectivePlan($store) ?? self::planForDisplay($store);

        return $plan?->max_transactions;
    }

    public static function maxCustomers(Store $store): ?int
    {
        if (! $store->isSubscriptionActive()) {
            return null;
        }
        $plan = self::effectivePlan($store) ?? self::planForDisplay($store);

        return $plan?->max_customers;
    }

    public static function currentProductCount(Store $store): int
    {
        return (int) $store->products()->count();
    }

    public static function currentCustomerCount(Store $store): int
    {
        return (int) $store->customers()->count();
    }

    public static function currentTransactionCount(Store $store): int
    {
        $startOfMonth = now()->startOfMonth();

        return (int) $store->transactions()->where('created_at', '>=', $startOfMonth)->count();
    }

    public static function assertCanCreateProduct(Store $store): void
    {
        $max = self::maxProducts($store);
        if ($max === null) {
            return;
        }
        if (self::currentProductCount($store) >= $max) {
            throw ValidationException::withMessages([
                'name' => "Paket Anda membatasi hingga {$max} produk. Naikkan paket di halaman langganan untuk menambah produk.",
            ]);
        }
    }

    public static function assertCanCreateCustomer(Store $store): void
    {
        $max = self::maxCustomers($store);
        if ($max === null) {
            return;
        }
        if (self::currentCustomerCount($store) >= $max) {
            throw ValidationException::withMessages([
                'name' => "Paket Anda membatasi hingga {$max} pelanggan. Naikkan paket di halaman langganan untuk menambah pelanggan.",
            ]);
        }
    }

    public static function assertCanCreateTransaction(Store $store): void
    {
        $max = self::maxTransactions($store);
        if ($max === null) {
            return;
        }
        if (self::currentTransactionCount($store) >= $max) {
            throw ValidationException::withMessages([
                'items' => "Paket Starter Anda membatasi hingga {$max} transaksi per bulan. Upgrade ke paket GrowUp untuk transaksi unlimited.",
            ]);
        }
    }

    /**
     * Fitur level GrowUp (slug `bisnis`): diskon, transfer antar outlet, dll.
     * Legacy: hanya jika langganan aktif tanpa tanggal akhir (subscription_end null) — akses penuh.
     * Langganan berjangka tanpa FK paket diperlakukan seperti Starter (aman, sesuai trial UMKM).
     */
    public static function allowsAdvancedRetailFeatures(Store $store): bool
    {
        if (! $store->isSubscriptionActive()) {
            return false;
        }

        $plan = self::effectivePlan($store);
        if ($plan === null) {
            if ($store->subscription_end === null) {
                return true;
            }
            $plan = self::planForDisplay($store);
        }

        if ($plan === null) {
            return false;
        }

        $slug = (string) $plan->slug;

        return ! in_array($slug, ['starter', 'starter-tahunan'], true);
    }

    public static function assertAdvancedRetail(Store $store, string $message): void
    {
        if (! self::allowsAdvancedRetailFeatures($store)) {
            throw ValidationException::withMessages([
                'name' => $message,
            ]);
        }
    }

    /**
     * Batas outlet per grup usaha (organization_id): max_stores dari paket terbaik
     * ditambah total extra_outlets yang sudah dibeli di semua toko dalam grup.
     * null = tidak dibatasi. Gudang Pusat tidak ikut kuota (dibatasi terpisah, gratis).
     */
    public static function maxOutletsAllowedForOrganization(int $organizationId): ?int
    {
        $stores = Store::query()
            ->where('organization_id', $organizationId)
            ->outlet()
            ->with('subscriptionPlan:id,max_stores')
            ->get(['id', 'subscription_plan_id', 'extra_outlets']);

        if ($stores->isEmpty()) {
            return null;
        }

        $limits = $stores
            ->map(fn (Store $s) => $s->subscriptionPlan?->max_stores)
            ->filter(fn ($m) => $m !== null);

        $baseMax = $limits->isNotEmpty() ? (int) $limits->max() : null;

        // Tambahkan total extra_outlets yang sudah dibeli
        $totalExtraOutlets = $stores->sum('extra_outlets');

        if ($baseMax === null && $totalExtraOutlets === 0) {
            return null;
        }

        // baseMax ?? 0 untuk handle grup yang sudah punya extra_outlets tapi base plan-nya null
        return ($baseMax ?? 0) + $totalExtraOutlets;
    }

    /**
     * @param  SubscriptionPlan|null  $fallbackPlan  Paket pembuat, dipakai bila grup masih kosong
     *                                               (mode "buat grup baru") agar limit tetap ditegakkan.
     */
    public static function assertCanAddStoreToOrganization(int $organizationId, ?SubscriptionPlan $fallbackPlan = null): void
    {
        $current = (int) Store::where('organization_id', $organizationId)->outlet()->count();
        $max = self::maxOutletsAllowedForOrganization($organizationId);

        // Grup baru/kosong: tak ada toko ber-plan → pakai max_stores paket pembuat.
        if ($max === null && $fallbackPlan !== null) {
            $max = $fallbackPlan->max_stores;
        }

        if ($max === null) {
            return;
        }

        if ($current >= $max) {
            throw ValidationException::withMessages([
                'name' => "Paket langganan pada grup usaha ini membatasi hingga {$max} outlet. Beli outlet tambahan di halaman Langganan untuk menambah.",
            ]);
        }
    }

    /**
     * Paket Starter gratis (slug starter / starter-tahunan, harga 0): tidak ada masa aktif / hitungan hari di UI.
     */
    public static function isStarterFreePlan(?SubscriptionPlan $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        return in_array($plan->slug, ['starter', 'starter-tahunan'], true) && (int) $plan->price === 0;
    }
}
