<?php

use App\Support\Kompetitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menampilkan halaman aplikasi kasir terbaik dengan semua aplikasi dan keterbukaan', function () {
    $apps = Kompetitor::summaries();
    $response = $this->get('/aplikasi-kasir-terbaik')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.url('/aplikasi-kasir-terbaik').'">', false)
        ->assertSee('application/ld+json', false)
        ->assertSee('Keterbukaan')
        ->assertDontSee('{paid', false)
        ->assertDontSee('{free', false);

    expect($apps)->toHaveCount(count(Kompetitor::all()) + 1)
        ->and($apps[0]['key'])->toBe('kasir-umkm');

    // Setiap kompetitor ditautkan ke halaman alternatifnya.
    foreach (Kompetitor::all() as $k) {
        $response->assertSee($k['name'])->assertSee('href="'.url('/'.$k['slug']).'"', false);
    }
});

it('memberi judul dengan jumlah aplikasi dan tahun data', function () {
    $count = count(Kompetitor::summaries());

    $this->get('/aplikasi-kasir-terbaik')
        ->assertSee('<title>'.$count.' Aplikasi Kasir Terbaik untuk UMKM 2026 | Kasir UMKM</title>', false);
});

it('menautkan halaman hub dari sitemap, footer blog, dan halaman alternatif', function () {
    $this->get('/sitemap.xml')->assertSee('<loc>'.url('/aplikasi-kasir-terbaik').'</loc>', false);
    $this->get('/blog')->assertSee('href="'.url('/aplikasi-kasir-terbaik').'"', false);
    $this->get('/alternatif-loyverse')->assertSee('href="'.url('/aplikasi-kasir-terbaik').'"', false);
});
