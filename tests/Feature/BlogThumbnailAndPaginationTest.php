<?php

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Simpan gambar asli berukuran tertentu ke disk publik palsu. */
function simpanGambar(string $nama, int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 120, 80, 220));

    ob_start();
    imagejpeg($im, null, 80);
    $biner = (string) ob_get_clean();
    imagedestroy($im);

    $path = 'articles/'.$nama;
    Storage::disk('public')->put($path, $biner);

    return $path;
}

function buatArtikel(string $judul, ?string $gambar = null, int $menitLalu = 1): Article
{
    return Article::query()->create([
        'title' => $judul,
        'slug' => Article::uniqueSlug($judul),
        'excerpt' => 'Ringkasan.',
        'content_markdown' => "Isi.\n\n## Bagian\n\nIsi.",
        'content_html' => '<p>Isi.</p>',
        'featured_image' => $gambar,
        'status' => Article::STATUS_PUBLISHED,
        'published_at' => now()->subMinutes($menitLalu),
        'reading_time' => 2,
    ]);
}

it('memakai dimensi asli gambar pada kartu, bukan ukuran tetap', function (int $w, int $h) {
    Storage::fake('public');

    buatArtikel('Artikel Bergambar', simpanGambar("u-{$w}x{$h}.jpg", $w, $h));

    $this->get('/blog')
        ->assertOk()
        ->assertSee('width="'.$w.'"', false)
        ->assertSee('height="'.$h.'"', false)
        // Ukuran tetap lama pernah membatalkan aspect-ratio CSS lalu
        // memotong gambar; pastikan tidak kembali.
        ->assertDontSee('width="600"', false)
        ->assertDontSee('height="338"', false);
})->with([
    'lanskap 16:9' => [1280, 720],
    'persegi' => [1200, 1200],
    'panorama' => [1600, 600],
    'potret' => [900, 1200],
]);

it('tidak memotong thumbnail kartu', function () {
    Storage::fake('public');

    buatArtikel('Artikel Bergambar', simpanGambar('u.jpg', 1280, 720));

    $html = $this->get('/blog')->assertOk()->getContent();

    // Aturan CSS kartu harus proporsional: tanpa rasio paksa & tanpa cover.
    preg_match('/\.card \.thumb img \{([^}]*)\}/', $html, $m);

    expect($m[1] ?? '')->toContain('height: auto')
        ->not->toContain('object-fit: cover')
        ->not->toContain('aspect-ratio');
});

it('membagi halaman setelah lebih dari 10 artikel', function () {
    Storage::fake('public');

    foreach (range(1, 11) as $i) {
        buatArtikel("Artikel Nomor {$i}", null, $i);
    }

    $halaman1 = $this->get('/blog')->assertOk();
    $halaman2 = $this->get('/blog?page=2')->assertOk();

    expect(substr_count($halaman1->getContent(), 'class="card"'))->toBe(10)
        ->and(substr_count($halaman2->getContent(), 'class="card"'))->toBe(1);

    // Halaman lanjutan tidak diindeks, tapi tautannya tetap diikuti.
    $halaman2->assertSee('noindex, follow', false);
});

it('tidak menampilkan pagination bila artikel 10 atau kurang', function () {
    Storage::fake('public');

    foreach (range(1, 10) as $i) {
        buatArtikel("Artikel Nomor {$i}", null, $i);
    }

    $this->get('/blog')->assertOk()->assertDontSee('class="pagination"', false);
});
