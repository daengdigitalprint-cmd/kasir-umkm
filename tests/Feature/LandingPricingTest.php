<?php

use App\Models\SubscriptionPlan;
use App\Support\LandingPricing;
use App\Support\LandingSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buatPaket(string $slug, string $name, float $price, array $extra = []): SubscriptionPlan
{
    return SubscriptionPlan::query()->create(array_merge([
        'name' => $name,
        'slug' => $slug,
        'price' => $price,
        'duration_days' => 30,
        'is_active' => true,
    ], $extra));
}

it('mengambil harga dari pengaturan admin, bukan nilai tetap', function () {
    buatPaket('starter', 'Starter', 0, ['max_products' => 30, 'max_customers' => 50, 'max_stores' => 1]);
    buatPaket('bisnis', 'GrowUp', 99000, ['max_stores' => 3]);
    buatPaket('bisnis-tahunan', 'GrowUp (tahunan)', 1188000, ['duration_days' => 365, 'max_stores' => 3]);

    $p = LandingPricing::payload();

    expect($p['paid']['monthly_label'])->toBe('Rp99 rb')
        ->and($p['paid']['yearly_label'])->toBe('Rp1.188.000')
        ->and($p['free']['price_label'])->toBe('Gratis')
        ->and($p['free']['max_products'])->toBe(30)
        ->and($p['free']['max_customers'])->toBe(50)
        ->and($p['paid']['max_stores'])->toBe(3);
});

it('ikut berubah saat admin mengubah harga', function () {
    buatPaket('bisnis', 'GrowUp', 99000);
    $tahunan = buatPaket('bisnis-tahunan', 'GrowUp (tahunan)', 990000, ['duration_days' => 365]);

    expect(LandingPricing::payload()['paid']['yearly_label'])->toBe('Rp990 rb');

    $tahunan->update(['price' => 1188000]);

    expect(LandingPricing::payload()['paid']['yearly_label'])->toBe('Rp1.188.000');
});

it('menyembunyikan klaim hemat bila tahunan tidak lebih murah', function () {
    buatPaket('bisnis', 'GrowUp', 99000);
    // 99.000 x 12 = 1.188.000, jadi tidak ada penghematan sama sekali.
    buatPaket('bisnis-tahunan', 'GrowUp (tahunan)', 1188000, ['duration_days' => 365]);

    expect(LandingPricing::payload()['yearly_saving_months'])->toBeNull();
});

it('menghitung penghematan bila tahunan memang lebih murah', function () {
    buatPaket('bisnis', 'GrowUp', 99000);
    buatPaket('bisnis-tahunan', 'GrowUp (tahunan)', 990000, ['duration_days' => 365]);

    // 1.188.000 - 990.000 = 198.000 = 2 bulan.
    expect(LandingPricing::payload()['yearly_saving_months'])->toBe(2);
});

it('memformat harga tanpa pembulatan yang menyesatkan', function (int $amount, string $expected) {
    expect(LandingPricing::formatPrice($amount))->toBe($expected);
})->with([
    'gratis' => [0, 'Gratis'],
    'ribuan bulat' => [99000, 'Rp99 rb'],
    'jutaan bulat' => [2000000, 'Rp2 jt'],
    'tidak bulat -> ditulis penuh' => [1188000, 'Rp1.188.000'],
    'pecahan ribuan -> ditulis penuh' => [99500, 'Rp99.500'],
]);

it('menyuntikkan harga terbaru ke HTML prerender', function () {
    buatPaket('bisnis', 'GrowUp', 99000);
    buatPaket('bisnis-tahunan', 'GrowUp (tahunan)', 1188000, ['duration_days' => 365]);

    $prerender = LandingSeo::landingPrerenderPath();

    if (! is_file($prerender)) {
        $this->markTestSkipped('Berkas prerender belum dibuat.');
    }

    $html = LandingSeo::landingPrerenderHtml();

    expect($html)->not->toBeNull()
        // Harga lama yang membeku di snapshot sudah tergantikan.
        ->and($html)->not->toContain('Rp990 rb')
        ->and($html)->toContain('Rp1.188.000')
        ->and($html)->toContain('Rp99 rb')
        // Klaim hemat ikut hilang karena tahunan tidak lebih murah.
        ->and($html)->not->toContain('Hemat 2 Bulan');
});
