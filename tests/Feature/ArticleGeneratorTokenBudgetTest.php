<?php

use App\Services\AI\ArticleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function artikelJson(): string
{
    return json_encode([
        'title' => 'Judul Uji',
        'meta_title' => 'Judul Uji',
        'meta_description' => str_repeat('a', 140),
        'excerpt' => 'Ringkasan uji.',
        'category' => 'Tips Kasir',
        'tags' => ['kasir'],
        'content_markdown' => "## Bagian\n\nIsi artikel.",
        'faq' => [['question' => 'Apa ini?', 'answer' => 'Uji.']],
    ]);
}

/** @return array<string, mixed> */
function balasan(string $content, string $finish = 'stop'): array
{
    return ['choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]]];
}

it('menaikkan plafon token sesuai panjang artikel yang diminta', function (string $length, int $diharapkan) {
    Http::fake(['*' => Http::response(balasan(artikelJson()), 200)]);

    (new ArticleGeneratorService)->generateArticle([
        'keyword' => 'aplikasi kasir',
        'strategy' => 'pillar',
        'length' => $length,
    ]);

    Http::assertSent(fn (Request $r) => $r->data()['max_tokens'] === $diharapkan);
})->with([
    'pendek' => ['short', 8000],
    'sedang' => ['medium', 12000],
    'panjang' => ['long', 16000],
]);

it('memakai anggaran sedang bila panjang tidak disebut', function () {
    Http::fake(['*' => Http::response(balasan(artikelJson()), 200)]);

    (new ArticleGeneratorService)->generateArticle([
        'keyword' => 'aplikasi kasir',
        'strategy' => 'howto',
    ]);

    Http::assertSent(fn (Request $r) => $r->data()['max_tokens'] === 12000);
});

it('menjelaskan penyebab saat jawaban AI terpotong', function () {
    // JSON terpotong di tengah, persis seperti saat menabrak batas token.
    Http::fake(['*' => Http::response(balasan('{"title":"Judul","content_markdown":"isi yang ter', 'length'), 200)]);

    expect(fn () => (new ArticleGeneratorService)->generateArticle([
        'keyword' => 'aplikasi kasir',
        'strategy' => 'pillar',
        'length' => 'long',
    ]))->toThrow(RuntimeException::class, 'terpotong');
});

it('meminta plafon lebih kecil untuk saran topik daripada artikel panjang', function () {
    Http::fake(['*' => Http::response(balasan(json_encode([
        ['title' => 'Ide', 'strategy' => 'howto', 'keyword' => 'kasir', 'reason' => 'bagus'],
    ])), 200)]);

    (new ArticleGeneratorService)->suggestTopics();

    // Anggaran berlaku sebagai batas bawah: tidak boleh di bawah 3000, dan
    // jauh lebih hemat daripada 16000 yang dipakai artikel panjang.
    Http::assertSent(function (Request $r) {
        $max = $r->data()['max_tokens'];

        return $max >= 3000 && $max < 16000;
    });
});
