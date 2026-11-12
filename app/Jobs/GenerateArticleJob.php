<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleGeneration;
use App\Services\AI\ArticleGeneratorService;
use App\Services\ArticleContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generate artikel panjang bisa beberapa menit. */
    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $generationId,
    ) {}

    public function handle(): void
    {
        $generation = ArticleGeneration::find($this->generationId);

        if (! $generation || $generation->status !== ArticleGeneration::STATUS_PENDING) {
            return;
        }

        $generation->update(['status' => ArticleGeneration::STATUS_PROCESSING]);

        try {
            $service = new ArticleGeneratorService;

            if ($generation->type === ArticleGeneration::TYPE_TOPICS) {
                $topics = $service->suggestTopics($generation->input['focus'] ?? null);

                $generation->update([
                    'status' => ArticleGeneration::STATUS_COMPLETED,
                    'result' => ['topics' => $topics],
                ]);

                return;
            }

            $data = $service->generateArticle($generation->input ?? []);

            $article = Article::create([
                'title' => $data['title'],
                'slug' => Article::uniqueSlug($data['title']),
                'excerpt' => $data['excerpt'],
                'content_markdown' => $data['content_markdown'],
                'content_html' => ArticleContentService::render(
                    $data['content_markdown'],
                    $data['title'],
                    $generation->input['keyword'] ?? null,
                ),
                'category' => $data['category'],
                'tags' => $data['tags'],
                'faq' => $data['faq'],
                'focus_keyword' => $generation->input['keyword'] ?? null,
                'meta_title' => $data['meta_title'],
                'meta_description' => $data['meta_description'],
                'status' => Article::STATUS_DRAFT,
                'is_pillar' => ($generation->input['strategy'] ?? null) === 'pillar',
                'strategy' => $generation->input['strategy'] ?? null,
                'reading_time' => Article::estimateReadingTime($data['content_markdown']),
                'generated_by_ai' => true,
                'ai_model' => $service->modelId(),
                'created_by' => $generation->created_by,
            ]);

            $generation->update([
                'status' => ArticleGeneration::STATUS_COMPLETED,
                'article_id' => $article->id,
                'result' => ['article_id' => $article->id, 'title' => $article->title],
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateArticleJob gagal', [
                'generation_id' => $generation->id,
                'message' => $e->getMessage(),
            ]);

            $generation->update([
                'status' => ArticleGeneration::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        ArticleGeneration::query()
            ->where('id', $this->generationId)
            ->whereIn('status', [ArticleGeneration::STATUS_PENDING, ArticleGeneration::STATUS_PROCESSING])
            ->update([
                'status' => ArticleGeneration::STATUS_FAILED,
                'error' => $e?->getMessage() ?? 'Job gagal tanpa pesan error.',
            ]);
    }
}
