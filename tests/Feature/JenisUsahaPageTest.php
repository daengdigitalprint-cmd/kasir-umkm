<?php

use App\Models\Article;
use App\Support\JenisUsaha;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menampilkan setiap halaman jenis usaha dengan meta SEO lengkap', function () {
    foreach (JenisUsaha::all() as $page) {
        $url = url('/'.$page['slug']);

        $this->get('/'.$page['slug'])
            ->assertOk()
            ->assertSee('<title>'.e($page['title']).'</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertSee('<meta name="description" content="'.e($page['description']).'">', false)
            ->assertSee('<h1>'.e($page['h1']).'</h1>', false)
            ->assertSee('application/ld+json', false)
            // Tautan ke halaman jenis usaha lain (hub & spoke).
            ->assertSee('href="'.url('/'.collect(JenisUsaha::slugs())->first(fn ($s) => $s !== $page['slug'])).'"', false);
    }
});

it('memberi title dan deskripsi yang unik dan tidak terpotong', function () {
    $pages = collect(JenisUsaha::all());

    expect($pages->pluck('title')->unique())->toHaveCount($pages->count())
        ->and($pages->pluck('description')->unique())->toHaveCount($pages->count())
        ->and($pages->pluck('h1')->unique())->toHaveCount($pages->count());

    foreach ($pages as $page) {
        expect(mb_strlen($page['title']))->toBeLessThanOrEqual(65, $page['slug'].' title')
            ->and(mb_strlen($page['description']))->toBeLessThanOrEqual(160, $page['slug'].' description')
            ->and(count($page['faq']))->toBeGreaterThanOrEqual(4)
            ->and(count($page['features']))->toBeGreaterThanOrEqual(6)
            ->and(count($page['problems']))->toBe(3);
    }
});

it('memasukkan semua halaman jenis usaha ke sitemap', function () {
    $response = $this->get('/sitemap.xml')->assertOk();

    foreach (JenisUsaha::slugs() as $slug) {
        $response->assertSee('<loc>'.url('/'.$slug).'</loc>', false);
    }
});

it('menautkan artikel blog yang relevan', function () {
    Article::query()->create([
        'title' => 'Cara Menghitung HPP Kopi Susu per Gelas',
        'slug' => 'hpp-kopi-susu',
        'excerpt' => 'Contoh hitungan.',
        'status' => Article::STATUS_PUBLISHED,
        'published_at' => now()->subDay(),
    ]);

    $this->get('/aplikasi-kasir-cafe')->assertSee(url('/blog/hpp-kopi-susu'), false);
});

it('menautkan halaman jenis usaha dari footer blog', function () {
    $this->get('/blog')
        ->assertOk()
        ->assertSee('href="'.url('/aplikasi-kasir-laundry').'"', false);
});

it('mengembalikan 404 untuk jenis usaha yang tidak ada', function () {
    $this->get('/aplikasi-kasir-tidak-ada')->assertNotFound();
});
