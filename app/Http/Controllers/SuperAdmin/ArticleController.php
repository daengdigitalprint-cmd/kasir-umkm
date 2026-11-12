<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Models\ArticleGeneration;
use App\Services\ArticleContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ArticleController extends Controller
{
    /** Daftar artikel + panel generate AI. */
    public function index(Request $request): Response
    {
        $articles = Article::query()
            ->when($request->query('q'), fn ($q, $search) => $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhere('focus_keyword', 'like', "%{$search}%");
            }))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Article $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'category' => $a->category,
                'status' => $a->status,
                'strategy' => $a->strategy,
                'is_pillar' => $a->is_pillar,
                'views' => $a->views,
                'reading_time' => $a->reading_time,
                'generated_by_ai' => $a->generated_by_ai,
                'published_at' => $a->published_at?->toISOString(),
                'created_at' => $a->created_at->toISOString(),
                'public_url' => $a->publicUrl(),
                'featured_image_url' => $a->featuredImageUrl(),
            ]);

        $stats = [
            'total' => Article::count(),
            'published' => Article::where('status', Article::STATUS_PUBLISHED)->count(),
            'draft' => Article::where('status', Article::STATUS_DRAFT)->count(),
            'total_views' => (int) Article::sum('views'),
        ];

        // Generate yang masih berjalan (untuk polling di UI)
        $activeGenerations = ArticleGeneration::query()
            ->whereIn('status', [ArticleGeneration::STATUS_PENDING, ArticleGeneration::STATUS_PROCESSING])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'type', 'status', 'input', 'created_at']);

        return Inertia::render('SuperAdmin/Articles/Index', [
            'articles' => $articles,
            'stats' => $stats,
            'strategies' => Article::STRATEGIES,
            'activeGenerations' => $activeGenerations,
            'filters' => [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
            ],
        ]);
    }

    /** Form artikel baru (manual). */
    public function create(): Response
    {
        return Inertia::render('SuperAdmin/Articles/Edit', [
            'article' => null,
            'strategies' => Article::STRATEGIES,
        ]);
    }

    /** Form edit artikel. */
    public function edit(Article $article): Response
    {
        return Inertia::render('SuperAdmin/Articles/Edit', [
            'article' => array_merge($article->toArray(), [
                'public_url' => $article->publicUrl(),
                'featured_image_url' => $article->featuredImageUrl(),
                // Dipakai UI sebagai placeholder agar admin tahu alt otomatisnya.
                'suggested_image_alt' => ArticleContentService::thumbnailAlt($article->title, $article->focus_keyword),
            ]),
            'strategies' => Article::STRATEGIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateArticle($request);

        $data['slug'] = Article::uniqueSlug($data['slug'] ?: $data['title']);
        $data['content_html'] = ArticleContentService::render(
            $data['content_markdown'],
            $data['title'],
            $data['focus_keyword'] ?? null,
        );
        $data['reading_time'] = Article::estimateReadingTime($data['content_markdown']);
        $data['created_by'] = $request->user()->id;

        if ($request->hasFile('featured_image')) {
            $data['featured_image'] = $request->file('featured_image')->store('articles', 'public');
        }

        $data['featured_image_alt'] = $this->resolveImageAlt($data);

        $article = Article::create($data);

        return redirect()
            ->route('superadmin.articles.edit', $article)
            ->with('success', 'Artikel tersimpan.');
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $data = $this->validateArticle($request, $article);

        $data['slug'] = $data['slug'] !== $article->slug
            ? Article::uniqueSlug($data['slug'] ?: $data['title'], $article->id)
            : $article->slug;
        $data['content_html'] = ArticleContentService::render(
            $data['content_markdown'],
            $data['title'],
            $data['focus_keyword'] ?? null,
        );
        $data['reading_time'] = Article::estimateReadingTime($data['content_markdown']);

        // Hapus thumbnail (dicentang admin), atau ganti dengan unggahan baru.
        if ($request->boolean('remove_featured_image') && $article->featured_image) {
            Storage::disk('public')->delete($article->featured_image);
            $data['featured_image'] = null;
            $data['featured_image_alt'] = null;
        }

        if ($request->hasFile('featured_image')) {
            if ($article->featured_image) {
                Storage::disk('public')->delete($article->featured_image);
            }

            $data['featured_image'] = $request->file('featured_image')->store('articles', 'public');
        }

        // Alt hanya relevan selama thumbnail ada.
        $hasImage = array_key_exists('featured_image', $data)
            ? $data['featured_image'] !== null
            : $article->featured_image !== null;

        $data['featured_image_alt'] = $hasImage ? $this->resolveImageAlt($data) : null;

        $article->update($data);

        return back()->with('success', 'Artikel diperbarui.');
    }

    public function destroy(Article $article): RedirectResponse
    {
        if ($article->featured_image) {
            Storage::disk('public')->delete($article->featured_image);
        }

        $article->delete();

        return redirect()
            ->route('superadmin.articles.index')
            ->with('success', 'Artikel dihapus.');
    }

    /**
     * Alt thumbnail: pakai isian admin, atau susun otomatis dari judul
     * dan kata kunci bila dibiarkan kosong (alt kosong merugikan SEO).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveImageAlt(array $data): string
    {
        $alt = trim((string) ($data['featured_image_alt'] ?? ''));

        if ($alt !== '') {
            return $alt;
        }

        return ArticleContentService::thumbnailAlt(
            (string) $data['title'],
            $data['focus_keyword'] ?? null,
        );
    }

    /** Publish sekarang / jadwalkan / kembalikan ke draft. */
    public function updateStatus(Request $request, Article $article): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,scheduled,published',
            'published_at' => 'nullable|date|required_if:status,scheduled',
        ]);

        $publishedAt = match ($validated['status']) {
            Article::STATUS_PUBLISHED => $article->published_at !== null && $article->published_at->isPast()
                ? $article->published_at
                : now(),
            Article::STATUS_SCHEDULED => $validated['published_at'],
            default => null,
        };

        $article->update([
            'status' => $validated['status'],
            'published_at' => $publishedAt,
        ]);

        return back()->with('success', 'Status artikel diperbarui.');
    }

    /** Mulai generate artikel AI (via queue). */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'required|string|min:3|max:150',
            'strategy' => 'required|in:'.implode(',', array_keys(Article::STRATEGIES)),
            'audience' => 'nullable|string|max:200',
            'length' => 'nullable|in:short,medium,long',
            'category' => 'nullable|string|max:100',
            'outline' => 'nullable|string|max:3000',
        ]);

        $generation = ArticleGeneration::create([
            'type' => ArticleGeneration::TYPE_ARTICLE,
            'status' => ArticleGeneration::STATUS_PENDING,
            'input' => $validated,
            'created_by' => $request->user()->id,
        ]);

        GenerateArticleJob::dispatch($generation->id);

        return response()->json(['generation_id' => $generation->id]);
    }

    /** Mulai generate saran topik (via queue). */
    public function suggestTopics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'focus' => 'nullable|string|max:200',
        ]);

        $generation = ArticleGeneration::create([
            'type' => ArticleGeneration::TYPE_TOPICS,
            'status' => ArticleGeneration::STATUS_PENDING,
            'input' => $validated,
            'created_by' => $request->user()->id,
        ]);

        GenerateArticleJob::dispatch($generation->id);

        return response()->json(['generation_id' => $generation->id]);
    }

    /** Status generate untuk polling UI. */
    public function generationStatus(ArticleGeneration $generation): JsonResponse
    {
        return response()->json([
            'id' => $generation->id,
            'type' => $generation->type,
            'status' => $generation->status,
            'result' => $generation->result,
            'error' => $generation->error,
            'article_id' => $generation->article_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateArticle(Request $request, ?Article $article = null): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'content_markdown' => 'required|string',
            'category' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'faq' => 'nullable|array',
            'faq.*.question' => 'required_with:faq|string|max:300',
            'faq.*.answer' => 'required_with:faq|string|max:1000',
            'focus_keyword' => 'nullable|string|max:150',
            'meta_title' => 'nullable|string|max:70',
            'meta_description' => 'nullable|string|max:300',
            'canonical_url' => 'nullable|url|max:255',
            'strategy' => 'nullable|in:'.implode(',', array_keys(Article::STRATEGIES)),
            'is_pillar' => 'boolean',
            'featured_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'featured_image_alt' => 'nullable|string|max:160',
            'remove_featured_image' => 'nullable|boolean',
        ]) + ['slug' => $request->input('slug', $article?->slug ?? '')];

        // Kolom bantu form, bukan kolom tabel.
        unset($validated['remove_featured_image']);

        // File ditangani terpisah (disimpan ke disk, bukan disimpan apa adanya).
        unset($validated['featured_image']);

        return $validated;
    }
}
