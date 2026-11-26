<?php

use App\Models\Article;
use App\Support\JenisUsaha;
use App\Support\KotaLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('menampilkan setiap halaman kota dengan meta SEO lengkap', function () {
    foreach (KotaLayanan::all() as $page) {
        $url = url('/'.$page['slug']);

        $this->get('/'.$page['slug'])
            ->assertOk()
            ->assertSee('<title>'.e($page['title']).'</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertSee('<meta name="description" content="'.e($page['description']).'">', false)
            ->assertSee('<h1>'.e($page['h1']).'</h1>', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            // Cakupan wilayah, bukan LocalBusiness (tidak ada kantor di kota itu).
            ->assertSee('"spatialCoverage":{"@type":"AdministrativeArea","name":"'.$page['name'].'"', false)
            ->assertDontSee('LocalBusiness', false)
            // Tautan balik ke indeks kota dan ke kota lain (hub & spoke).
            ->assertSee('href="'.url('/'.KotaLayanan::INDEX_PATH).'"', false)
            ->assertSee('href="'.url('/'.collect(KotaLayanan::slugs())->first(fn ($s) => $s !== $page['slug'])).'"', false);
    }
});

it('menampilkan indeks kota yang menaut ke semua kota', function () {
    $response = $this->get('/'.KotaLayanan::INDEX_PATH)
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.url('/'.KotaLayanan::INDEX_PATH).'">', false)
        ->assertSee('<h1>Aplikasi Kasir untuk UMKM di Berbagai Kota</h1>', false)
        ->assertSee('"@type":"CollectionPage"', false)
        ->assertSee('"@type":"ItemList"', false);

    foreach (KotaLayanan::all() as $page) {
        $response->assertSee('href="'.url('/'.$page['slug']).'"', false);
    }
});

it('memberi title, deskripsi, dan H1 yang unik dan tidak terpotong', function () {
    $pages = collect(KotaLayanan::all());

    expect($pages->pluck('title')->unique())->toHaveCount($pages->count())
        ->and($pages->pluck('description')->unique())->toHaveCount($pages->count())
        ->and($pages->pluck('h1')->unique())->toHaveCount($pages->count())
        ->and($pages->pluck('lead')->unique())->toHaveCount($pages->count());

    foreach ($pages as $page) {
        expect(mb_strlen($page['title']))->toBeLessThanOrEqual(65, $page['slug'].' title')
            ->and(mb_strlen($page['description']))->toBeLessThanOrEqual(160, $page['slug'].' description')
            ->and($page['title'])->toContain($page['name'])
            ->and($page['h1'])->toContain($page['name'])
            ->and(count($page['usaha']))->toBe(4)
            ->and(count($page['tantangan']))->toBe(3)
            ->and(count($page['flow']))->toBe(4)
            ->and(count($page['faq']))->toBeGreaterThanOrEqual(4);
    }
});

it('menulis isi yang berbeda di setiap kota (bukan sekadar ganti nama kota)', function () {
    // Kumpulkan semua kalimat isi tiap kota, samakan nama kotanya, lalu pastikan
    // tidak ada kalimat yang dipakai di dua kota.
    $seen = [];

    foreach (KotaLayanan::all() as $page) {
        $texts = array_merge(
            [$page['lead'], $page['bantuan'], $page['usaha_sub'], $page['tantangan_sub'], $page['flow_sub']],
            array_column($page['usaha'], 'desc'),
            array_column($page['tantangan'], 'detail'),
            array_column($page['tantangan'], 'solution'),
            array_column($page['flow'], 'desc'),
            array_column($page['faq'], 'q'),
            array_column($page['faq'], 'a'),
        );

        foreach ($texts as $text) {
            $normal = mb_strtolower(str_replace($page['name'], '{kota}', $text));

            expect($seen)->not->toHaveKey($normal, $page['slug'].' mengulang kalimat kota lain: '.$text);
            $seen[$normal] = $page['slug'];
        }
    }
});

it('hanya menaut ke halaman jenis usaha dan kota yang benar-benar ada', function () {
    $keys = array_column(KotaLayanan::all(), 'key');

    foreach (KotaLayanan::all() as $page) {
        foreach ($page['usaha'] as $usaha) {
            expect(JenisUsaha::find($usaha['jenis']))->not->toBeNull($page['slug'].': '.$usaha['jenis']);
        }
        foreach ($page['nearby'] as $near) {
            expect($keys)->toContain($near)
                ->and($near)->not->toBe($page['key']);
        }
    }

    // Alamat kota tidak boleh bertabrakan dengan halaman jenis usaha.
    expect(array_intersect(KotaLayanan::slugs(), JenisUsaha::slugs()))->toBeEmpty();
});

it('tidak mengaku punya kantor di luar Gowa', function () {
    foreach (KotaLayanan::all() as $page) {
        $html = $this->get('/'.$page['slug'])->getContent();

        expect($html)->toContain('Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa')
            ->and($html)->not->toContain('kantor cabang');
    }
});

it('menyesuaikan jam layanan dengan zona waktu kota', function () {
    expect(KotaLayanan::jamLayanan('WITA'))->toBe('08.00 – 17.00 WITA')
        ->and(KotaLayanan::jamLayanan('WIB'))->toBe('07.00 – 16.00 WIB')
        ->and(KotaLayanan::jamLayanan('WIT'))->toBe('09.00 – 18.00 WIT');

    $this->get('/aplikasi-kasir-jakarta')->assertSee('07.00 – 16.00 WIB');
    $this->get('/aplikasi-kasir-makassar')->assertSee('08.00 – 17.00 WITA');
});

it('memasukkan indeks dan semua halaman kota ke sitemap', function () {
    $response = $this->get('/sitemap.xml')->assertOk()
        ->assertSee('<loc>'.url('/'.KotaLayanan::INDEX_PATH).'</loc>', false);

    foreach (KotaLayanan::slugs() as $slug) {
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

    $this->get('/aplikasi-kasir-makassar')->assertSee(url('/blog/hpp-kopi-susu'), false);
});

it('menautkan halaman kota dari footer blog dan halaman jenis usaha', function () {
    $this->get('/blog')
        ->assertOk()
        ->assertSee('href="'.url('/aplikasi-kasir-makassar').'"', false)
        ->assertSee('href="'.url('/'.KotaLayanan::INDEX_PATH).'"', false);

    $this->get('/aplikasi-kasir-cafe')
        ->assertOk()
        ->assertSee('href="'.url('/aplikasi-kasir-gowa').'"', false);
});

it('mengembalikan 404 untuk kota yang tidak ada', function () {
    $this->get('/aplikasi-kasir-kota-tidak-ada')->assertNotFound();
    $this->get('/'.KotaLayanan::INDEX_PATH.'/makassar')->assertNotFound();
});
