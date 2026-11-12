<?php

namespace App\Providers;

use App\Services\Rag\Chunker;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
