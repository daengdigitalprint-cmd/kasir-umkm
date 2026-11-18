<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paket GrowUp kini termasuk 1 outlet (sebelumnya 3). Outlet berikutnya dibeli
 * sebagai add-on Rp85.000/outlet/bulan (OutletAddOnService).
 *
 * Batas hanya dicek saat menambah outlet baru, jadi outlet yang sudah ada tetap jalan.
 */
return new class extends Migration
{
    private const SLUGS = ['bisnis', 'bisnis-tahunan', 'promo-onboarding-tahunan'];

    private const NEW_TEXT = '1 Outlet (tambah outlet Rp85.000/bulan)';

    public function up(): void
    {
        $this->apply(1, ['Hingga 3 Outlet', 'Hingga 5 Outlet'], self::NEW_TEXT);
    }

    public function down(): void
    {
        $this->apply(3, [self::NEW_TEXT], 'Hingga 3 Outlet');
    }

    /** @param  list<string>  $from */
    private function apply(int $maxStores, array $from, string $to): void
    {
        $plans = DB::table('subscription_plans')
            ->whereIn('slug', self::SLUGS)
            ->get(['id', 'features']);

        foreach ($plans as $plan) {
            $features = json_decode((string) $plan->features, true);
            $features = is_array($features)
                ? array_map(fn ($f) => is_string($f) ? str_replace($from, $to, $f) : $f, $features)
                : $features;

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'max_stores' => $maxStores,
                'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
