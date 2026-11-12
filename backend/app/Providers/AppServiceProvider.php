<?php

namespace App\Providers;

use App\Services\Rag\Chunker;
use App\Services\Rag\ClaudeClient;
use App\Services\Rag\Contracts\Embedder;
use App\Services\Rag\Ingestor;
use App\Services\Rag\OpenAiEmbedder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Chunker 自体は設定を知らない純粋なクラス。config/rag.php の値はここで渡す
        $this->app->bind(Chunker::class, fn () => new Chunker(
            size: (int) config('rag.chunk.size'),
            overlap: (int) config('rag.chunk.overlap'),
            minSize: (int) config('rag.chunk.min_size'),
        ));

        // 埋め込みはインターフェースで受け取る。別の埋め込み API に替えるときは、ここだけ変える
        $this->app->bind(Embedder::class, fn () => new OpenAiEmbedder(
            apiKey: config('rag.openai.api_key'),
            model: (string) config('rag.embedding.model'),
            dimensions: (int) config('rag.embedding.dimensions'),
            batchSize: (int) config('rag.embedding.batch_size'),
            retryDelaysMs: config('rag.embedding.retry_delays_ms'),
            baseUrl: (string) config('rag.openai.base_url'),
            timeout: (int) config('rag.openai.timeout'),
        ));

        $this->app->bind(ClaudeClient::class, fn () => new ClaudeClient(
            apiKey: config('rag.anthropic.api_key'),
            model: (string) config('rag.anthropic.model'),
            version: (string) config('rag.anthropic.version'),
            maxTokens: (int) config('rag.anthropic.max_tokens'),
            retryDelaysMs: config('rag.anthropic.retry_delays_ms'),
            baseUrl: (string) config('rag.anthropic.base_url'),
            timeout: (int) config('rag.anthropic.timeout'),
        ));

        $this->app->bind(Ingestor::class, fn ($app) => new Ingestor(
            embedder: $app->make(Embedder::class),
            chunkSize: (int) config('rag.chunk.size'),
            chunkOverlap: (int) config('rag.chunk.overlap'),
            chunkMinSize: (int) config('rag.chunk.min_size'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
