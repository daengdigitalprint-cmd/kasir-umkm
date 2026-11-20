<?php

use App\Support\Kompetitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menampilkan setiap halaman alternatif dengan meta SEO dan sumber bertanggal', function () {
    foreach (Kompetitor::all() as $k) {
        $response = $this->get('/'.$k['slug'])
            ->assertOk()
            ->assertSee('<title>Alternatif '.e($k['name']).' untuk UMKM Indonesia | Kasir UMKM</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/'.$k['slug']).'">', false)
            ->assertSee('application/ld+json', false)
            // Klaim tentang kompetitor wajib bersumber, bertanggal, dan ada penafian.
            ->assertSee($k['checked_label'])
            ->assertSee('tidak berafiliasi');

        foreach ($k['sources'] as $src) {
            $response->assertSee($src['url'], false);
        }

        // Placeholder harga harus sudah terisi.
        $response->assertDontSee('{paid', false)->assertDontSee('{free', false)->assertDontSee('{outlet_addon}', false);
    }
});

it('memasukkan semua halaman alternatif ke sitemap dan footer blog', function () {
    $sitemap = $this->get('/sitemap.xml');
    $blog = $this->get('/blog');

    foreach (Kompetitor::all() as $k) {
        $sitemap->assertSee('<loc>'.url('/'.$k['slug']).'</loc>', false);
        $blog->assertSee('href="'.url('/'.$k['slug']).'"', false);
    }
});

it('menyimpan data kompetitor yang lengkap dan bertanggal', function () {
    foreach (Kompetitor::all() as $k) {
        expect($k['checked_at'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($k['sources'])->not->toBeEmpty()
            ->and($k['strengths'])->not->toBeEmpty()
            ->and(mb_strlen($k['description']))->toBeLessThanOrEqual(160, $k['key'])
            ->and(mb_strlen('Alternatif '.$k['name'].' untuk UMKM Indonesia | Kasir UMKM'))->toBeLessThanOrEqual(65, $k['key']);
    }
});

it('menghitung contoh biaya Kasir UMKM untuk dua outlet', function () {
    \App\Models\SubscriptionPlan::query()->create([
        'name' => 'GrowUp', 'slug' => 'bisnis', 'price' => 99000, 'duration_days' => 30, 'is_active' => true,
    ]);

    // 99.000 + 85.000 outlet tambahan = 184.000.
    $this->get('/alternatif-moka-pos')->assertSee('Rp184 rb / bulan');
});
