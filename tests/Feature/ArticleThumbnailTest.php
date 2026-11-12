<?php

use App\Models\Article;
use App\Models\User;
use App\Services\ArticleContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function superAdminUser(): User
{
    return User::query()->create([
        'name' => 'Super Admin',
        'email' => 'sa-'.uniqid().'@example.test',
        'password' => bcrypt('rahasia-test'),
        'is_super_admin' => true,
    ]);
}

/** @return array<string, mixed> */
function articlePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Panduan Aplikasi Kasir untuk Warung',
        'content_markdown' => "Paragraf pembuka artikel.\n\n## Bagian Pertama\n\nIsi bagian.",
        'focus_keyword' => 'aplikasi kasir warung',
    ], $overrides);
}

it('menyimpan thumbnail dan mengisi alt otomatis saat alt dikosongkan', function () {
    Storage::fake('public');

    $this->actingAs(superAdminUser())
        ->post('/superadmin/articles', articlePayload([
            'featured_image' => UploadedFile::fake()->image('thumb.jpg', 1200, 675),
        ]))
        ->assertRedirect();

    $article = Article::query()->firstOrFail();

    expect($article->featured_image)->not->toBeNull();
    Storage::disk('public')->assertExists($article->featured_image);

    // Judul sudah memuat kata kunci sebagian, jadi alt tetap informatif.
    expect($article->featured_image_alt)->toContain('Panduan Aplikasi Kasir untuk Warung');
    expect($article->featuredImageUrl())->toContain('storage/');
});

it('menghormati alt yang diisi admin', function () {
    Storage::fake('public');

    $this->actingAs(superAdminUser())
        ->post('/superadmin/articles', articlePayload([
            'featured_image' => UploadedFile::fake()->image('thumb.jpg'),
            'featured_image_alt' => 'Kasir sedang mencatat penjualan di warung kelontong',
        ]));

    expect(Article::query()->firstOrFail()->featured_image_alt)
        ->toBe('Kasir sedang mencatat penjualan di warung kelontong');
});

it('mengganti thumbnail lama dan menghapus filenya', function () {
    Storage::fake('public');
    $admin = superAdminUser();

    $this->actingAs($admin)->post('/superadmin/articles', articlePayload([
        'featured_image' => UploadedFile::fake()->image('lama.jpg'),
    ]));

    $article = Article::query()->firstOrFail();
    $old = $article->featured_image;

    $this->actingAs($admin)->post("/superadmin/articles/{$article->id}", articlePayload([
        '_method' => 'put',
        'featured_image' => UploadedFile::fake()->image('baru.jpg'),
    ]));

    $article->refresh();

    expect($article->featured_image)->not->toBe($old);
    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($article->featured_image);
});

it('menghapus thumbnail beserta altnya saat diminta', function () {
    Storage::fake('public');
    $admin = superAdminUser();

    $this->actingAs($admin)->post('/superadmin/articles', articlePayload([
        'featured_image' => UploadedFile::fake()->image('thumb.jpg'),
    ]));

    $article = Article::query()->firstOrFail();
    $path = $article->featured_image;

    $this->actingAs($admin)->post("/superadmin/articles/{$article->id}", articlePayload([
        '_method' => 'put',
        'remove_featured_image' => true,
    ]));

    $article->refresh();

    expect($article->featured_image)->toBeNull()
        ->and($article->featured_image_alt)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('mengisi alt gambar di dalam konten dari judul bagian terdekat', function () {
    $html = ArticleContentService::render(
        "Pembuka.\n\n![](/a.png)\n\n## Cara Menghitung HPP\n\n![](/b.png)\n\n![](/c.png)",
        'Panduan HPP Warung Makan',
        'hitung hpp',
    );

    expect($html)
        ->toContain('alt="Panduan HPP Warung Makan"')
        ->toContain('alt="Cara Menghitung HPP - Panduan HPP Warung Makan"')
        ->toContain('(gambar 2)')
        ->toContain('loading="lazy"');
});

it('tidak menimpa alt yang sudah ditulis penulis', function () {
    $html = ArticleContentService::render('![Grafik omzet harian](/a.png)', 'Judul Artikel');

    expect($html)->toContain('alt="Grafik omzet harian"');
});

it('menolak berkas yang bukan gambar', function () {
    Storage::fake('public');

    $this->actingAs(superAdminUser())
        ->post('/superadmin/articles', articlePayload([
            'featured_image' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ]))
        ->assertSessionHasErrors('featured_image');

    expect(Article::query()->count())->toBe(0);
});
