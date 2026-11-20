<?php

use App\Support\Kompetitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menampilkan halaman alternatif Loyverse dengan meta SEO dan sumber bertanggal', function () {
    $k = Kompetitor::find('loyverse');

    $this->get('/alternatif-loyverse')
        ->assertOk()
        ->assertSee('<title>Alternatif Loyverse untuk UMKM Indonesia | Kasir UMKM</title>', false)
        ->assertSee('<link rel="canonical" href="'.url('/alternatif-loyverse').'">', false)
        ->assertSee('application/ld+json', false)
        // Klaim tentang kompetitor wajib bersumber dan bertanggal.
        ->assertSee($k['source'], false)
        ->assertSee($k['checked_label'])
        ->assertSee('tidak berafiliasi');
});

it('memasukkan halaman alternatif ke sitemap dan footer blog', function () {
    $this->get('/sitemap.xml')->assertSee('<loc>'.url('/alternatif-loyverse').'</loc>', false);
    $this->get('/blog')->assertSee('href="'.url('/alternatif-loyverse').'"', false);
});

it('menyimpan tanggal cek data kompetitor yang valid', function () {
    $k = Kompetitor::find('loyverse');

    expect($k['checked_at'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($k['source'])->toStartWith('https://loyverse.com/');
});
