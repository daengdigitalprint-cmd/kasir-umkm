<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('provider', 50)->default('openai');
            $table->string('base_url', 500)->default('https://api.openai.com/v1');
            $table->string('model_id', 100)->default('gpt-4o');
            $table->text('api_key');
            $table->integer('max_tokens')->default(4096);
            $table->decimal('temperature', 3, 2)->default(0.7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_configurations');
    }
};
