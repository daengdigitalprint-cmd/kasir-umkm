<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->longText('content_html')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->json('tags')->nullable();
            $table->json('faq')->nullable();

            // SEO on-page
            $table->string('focus_keyword')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('canonical_url')->nullable();

            // Workflow
            $table->string('status', 20)->default('draft')->index(); // draft|scheduled|published
            $table->timestamp('published_at')->nullable()->index();
            $table->boolean('is_pillar')->default(false);
            $table->string('strategy', 50)->nullable(); // pillar|cluster|howto|listicle|comparison|faq|programmatic

            // Tracking
            $table->unsignedSmallInteger('reading_time')->default(0); // menit
            $table->unsignedBigInteger('views')->default(0);
            $table->boolean('generated_by_ai')->default(false);
            $table->string('ai_model')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('article_generations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('article'); // article|topics
            $table->string('status', 20)->default('pending')->index(); // pending|processing|completed|failed
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_generations');
        Schema::dropIfExists('articles');
    }
};
