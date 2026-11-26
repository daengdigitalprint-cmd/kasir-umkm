<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Support\JenisUsaha;
use App\Support\KotaLayanan;
use App\Support\LandingPricing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Halaman SEO lokal "Aplikasi Kasir di [kota]" dan indeks semua kota
 * (Blade murni agar crawler mendapat HTML lengkap, sama seperti blog).
 */
class KotaController extends Controller
{
    public function index(): View
    {
        return view('kota.index', [
            'groups' => KotaLayanan::byProvince(),
            'total' => count(KotaLayanan::slugs()),
            'jenisUsaha' => JenisUsaha::all(),
            'pricing' => LandingPricing::payload(),
        ]);
    }

    public function show(Request $request): View
    {
        // Slug diambil dari URL: route didaftarkan satu per satu dari KotaLayanan::slugs().
        $slug = ltrim($request->path(), '/');
        $page = KotaLayanan::find($slug);
        abort_if($page === null, 404);

        // Kartu jenis usaha menaut ke halaman jenis usaha yang cocok (bila halamannya ada).
        $page['usaha'] = array_map(function (array $usaha) {
            $usaha['jenis_page'] = JenisUsaha::find($usaha['jenis']);

            return $usaha;
        }, $page['usaha']);

        return view('kota.show', [
            'page' => $page,
            'pricing' => LandingPricing::payload(),
            'jamLayanan' => KotaLayanan::jamLayanan($page['zone']),
            'articles' => $this->relatedArticles($page['article_keywords']),
            'others' => KotaLayanan::others($slug),
            'jenisUsaha' => JenisUsaha::all(),
        ]);
    }

    /**
     * Artikel blog yang judulnya memuat salah satu kata kunci kota ini.
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
