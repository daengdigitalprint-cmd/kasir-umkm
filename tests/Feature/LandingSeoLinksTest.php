<?php

use App\Models\Article;
use App\Support\LandingSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buatArtikel(string $judul, string $slug, string $status = Article::STATUS_PUBLISHED, ?string $publishedAt = '-1 day'): Article
{
    return Article::query()->create([
        'title' => $judul,
        'slug' => $slug,
        'excerpt' => 'Ringkasan '.$judul,
        'status' => $status,
        'published_at' => $publishedAt ? now()->modify($publishedAt) : null,
    ]);
}

it('mengirim artikel terbit terbaru ke beranda', function () {
    buatArtikel('Artikel lama', 'artikel-lama', publishedAt: '-10 days');
    buatArtikel('Artikel baru', 'artikel-baru', publishedAt: '-1 day');
    buatArtikel('Masih draft', 'masih-draft', Article::STATUS_DRAFT, null);

    $articles = LandingSeo::latestArticles();

    expect($articles)->toHaveCount(2)
        ->and($articles[0]['title'])->toBe('Artikel baru')
        ->and($articles[0]['url'])->toBe(url('/blog/artikel-baru'))
        ->and(collect($articles)->pluck('title'))->not->toContain('Masih draft');
});

it('mengganti daftar artikel di HTML prerender dengan artikel terbaru', function () {
    if (! is_file(LandingSeo::landingPrerenderPath())) {
        $this->markTestSkipped('Prerender landing belum dibuat.');
    }

    buatArtikel('Cara Menghitung HPP <Seblak>', 'hpp-seblak');

    $html = LandingSeo::landingPrerenderHtml();

    expect($html)->toContain('href="'.url('/blog/hpp-seblak').'"')
        ->and($html)->toContain('Cara Menghitung HPP &lt;Seblak&gt;')
        // Placeholder dari scripts/prerender-landing.mjs tidak boleh tampil.
        ->and($html)->not->toContain('<h3 class="lp-blog-title">Artikel</h3>');
});

it('menampilkan thumbnail artikel di HTML prerender', function () {
    if (! is_file(LandingSeo::landingPrerenderPath())) {
        $this->markTestSkipped('Prerender landing belum dibuat.');
    }

    $artikel = buatArtikel('Cara Stok Opname', 'stok-opname');
    $artikel->update(['featured_image' => 'articles/contoh.png', 'featured_image_alt' => 'Ilustrasi stok opname']);

    $html = LandingSeo::landingPrerenderHtml();

    expect($html)->toContain('<span class="lp-blog-thumb"><img src="'.asset('storage/articles/contoh.png').'" alt="Ilustrasi stok opname"')
        ->and(LandingSeo::latestArticles()[0]['image'])->toBe(asset('storage/articles/contoh.png'));
});

it('membuang bagian blog di prerender bila belum ada artikel terbit', function () {
    if (! is_file(LandingSeo::landingPrerenderPath())) {
        $this->markTestSkipped('Prerender landing belum dibuat.');
    }

    expect(LandingSeo::landingPrerenderHtml())->not->toContain('lp-blog-grid');
});

it('memberi meta SEO server untuk halaman syarat dan refund', function (string $path, string $title) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<title inertia>'.e($title).'</title>', false)
        ->assertSee('<link rel="canonical" href="'.url($path).'">', false)
        ->assertSee('<meta name="description"', false);
})->with([
    'syarat' => ['/terms', 'Syarat & Ketentuan — Kasir UMKM by MaccaPOS'],
    'refund' => ['/refund', 'Kebijakan Refund — Kasir UMKM by MaccaPOS'],
]);

it('mengalihkan /index.php ke beranda secara permanen', function () {
    $this->get('/index.php')->assertRedirect('/')->assertStatus(301);
});

it('deskripsi beranda tidak lebih dari 160 karakter', function () {
    expect(mb_strlen(LandingSeo::HOME_DESCRIPTION))->toBeLessThanOrEqual(160);
});
