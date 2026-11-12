<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman blog publik (SEO). Sengaja memakai Blade murni (bukan Inertia)
 * agar crawler mendapat HTML lengkap tanpa JavaScript.
 */
class BlogController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->query('kategori');
        $tag = $request->query('tag');

        $articles = Article::query()
            ->published()
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($tag, fn ($q) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('published_at')
            ->paginate(12)
            ->withQueryString();

        $categories = Article::query()
            ->published()
            ->whereNotNull('category')
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('blog.index', [
            'articles' => $articles,
            'categories' => $categories,
            'activeCategory' => $category,
            'activeTag' => $tag,
            'popularTags' => $this->popularTags(),
            'popularArticles' => $this->popularArticles(),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $article = Article::query()->where('slug', $slug)->firstOrFail();

        // Draft/terjadwal hanya bisa dilihat super admin (untuk preview).
        if (! $article->isPublished()) {
            abort_unless($request->user()?->isSuperAdmin() ?? false, 404);
        } else {
            $article->increment('views');
        }

        $related = $article->related(3);

        [$contentHtml, $toc] = $this->injectHeadingAnchors((string) $article->content_html);

        return view('blog.show', [
            'article' => $article,
            'related' => $related,
            'contentHtml' => $contentHtml,
            'toc' => $toc,
            'isPreview' => ! $article->isPublished(),
            'popularTags' => $this->popularTags(),
            'popularArticles' => $this->popularArticles($article->id),
        ]);
    }

    /**
     * Tag terpopuler dihitung dari jumlah pemakaian di artikel terbit.
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function popularTags(int $limit = 12): array
    {
        $counts = [];

        Article::query()
            ->published()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->each(function ($tags) use (&$counts) {
                foreach ((array) $tags as $tag) {
                    $tag = trim((string) $tag);

                    if ($tag === '') {
                        continue;
                    }

                    $key = mb_strtolower($tag);
                    $counts[$key] ??= ['name' => $tag, 'count' => 0];
                    $counts[$key]['count']++;
                }
            });

        uasort($counts, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return array_slice(array_values($counts), 0, $limit);
    }

    /** Artikel terpopuler berdasarkan jumlah dibaca. */
    private function popularArticles(?int $excludeId = null, int $limit = 5)
    {
        return Article::query()
            ->published()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderByDesc('views')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'category', 'views', 'reading_time', 'published_at']);
    }

    /**
     * Tambah id pada heading H2 untuk daftar isi (anchor link).
     *
     * @return array{0: string, 1: array<int, array{id: string, text: string}>}
     */
    private function injectHeadingAnchors(string $html): array
    {
        $toc = [];
        $used = [];

        $html = (string) preg_replace_callback(
            '/<h2([^>]*)>(.*?)<\/h2>/is',
            function (array $m) use (&$toc, &$used) {
                $text = trim(strip_tags($m[2]));
                $id = \Illuminate\Support\Str::slug($text) ?: 'bagian';

                $base = $id;
                $i = 2;
                while (isset($used[$id])) {
                    $id = "{$base}-{$i}";
                    $i++;
                }
                $used[$id] = true;

                $toc[] = ['id' => $id, 'text' => $text];

                return "<h2 id=\"{$id}\"{$m[1]}>{$m[2]}</h2>";
            },
            $html,
        );

        return [$html, $toc];
    }
}
