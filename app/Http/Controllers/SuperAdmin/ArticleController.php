<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Models\ArticleGeneration;
use App\Services\AI\ArticleGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ]),
            'strategies' => Article::STRATEGIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateArticle($request);

        $data['slug'] = Article::uniqueSlug($data['slug'] ?: $data['title']);
        $data['content_html'] = ArticleGeneratorService::markdownToHtml($data['content_markdown']);
        $data['reading_time'] = Article::estimateReadingTime($data['content_markdown']);
        $data['created_by'] = $request->user()->id;

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
        $data['content_html'] = ArticleGeneratorService::markdownToHtml($data['content_markdown']);
        $data['reading_time'] = Article::estimateReadingTime($data['content_markdown']);

        $article->update($data);

        return back()->with('success', 'Artikel diperbarui.');
    }

    public function destroy(Article $article): RedirectResponse
    {
        $article->delete();

        return redirect()
            ->route('superadmin.articles.index')
            ->with('success', 'Artikel dihapus.');
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
        return $request->validate([
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
        ]) + ['slug' => $request->input('slug', $article?->slug ?? '')];
    }
}
