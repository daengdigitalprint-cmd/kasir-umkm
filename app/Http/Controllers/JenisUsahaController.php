<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Support\JenisUsaha;
use App\Support\LandingPricing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Halaman SEO "Aplikasi Kasir untuk [jenis usaha]" (Blade murni agar crawler
 * mendapat HTML lengkap, sama seperti blog).
 */
class JenisUsahaController extends Controller
{
    public function show(Request $request): View
    {
        // Slug diambil dari URL: route didaftarkan satu per satu dari JenisUsaha::slugs().
        $slug = ltrim($request->path(), '/');
        $page = JenisUsaha::find($slug);
        abort_if($page === null, 404);

        return view('jenis-usaha.show', [
            'page' => $page,
            'pricing' => LandingPricing::payload(),
            'articles' => $this->relatedArticles($page['article_keywords']),
            'others' => collect(JenisUsaha::all())->reject(fn (array $p) => $p['slug'] === $slug)->values()->all(),
        ]);
    }

    /**
     * Artikel blog yang judulnya memuat salah satu kata kunci jenis usaha ini.
     *
     * @param  list<string>  $keywords
     * @return Collection<int, Article>
     */
    private function relatedArticles(array $keywords): Collection
    {
        if ($keywords === []) {
            return collect();
        }

        try {
            return Article::query()
                ->published()
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhere('title', 'like', '%'.$kw.'%');
                    }
                })
                ->orderByDesc('published_at')
                ->limit(4)
                ->get(['title', 'slug', 'excerpt', 'published_at']);
        } catch (\Throwable) {
            return collect();
        }
    }
}
