<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\Ingestor;
use Illuminate\Console\Command;
use Throwable;

/**
 * 取り込み（要件定義書 第4.1章）。処理は Ingestor にあり、ここは引数の受け取りと結果の表示だけ。
 */
class RagIngest extends Command
{
    protected $signature = 'rag:ingest
        {path? : 取り込むディレクトリ（省略時は storage/app/samples）}
        {--fresh : 候補者・文書・チャンクを全件消してから取り込む}
        {--chunk-size= : 分割の上限文字数（省略時は RAG_CHUNK_SIZE）}
        {--overlap= : 前のチャンク末尾を重ねる文字数（省略時は RAG_CHUNK_OVERLAP）}';

    protected $description = '文書を読み込み、分割・埋め込みして DB に保存する（OpenAI Embeddings API を使う）';

    public function handle(Ingestor $ingestor): int
    {
        $path = $this->argument('path') ?? storage_path('app/samples');
        $chunkSize = $this->option('chunk-size');
        $overlap = $this->option('overlap');

        try {
            $report = $ingestor->ingest(
                path: $path,
                fresh: (bool) $this->option('fresh'),
                chunkSize: $chunkSize === null ? null : (int) $chunkSize,
                chunkOverlap: $overlap === null ? null : (int) $overlap,
            );
        } catch (Throwable $e) {
            $this->error('取り込みに失敗した: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($report->skipped as $skipped) {
            $this->line("スキップ: {$skipped}");
        }

        $this->info('今回の取り込み');
        $this->table(['項目', '値'], [
            ['文書数', count($report->ingested)],
            ['スキップした文書数', count($report->skipped)],
            ['チャンク数', $report->chunkCount],
            ['平均文字数', number_format($report->averageChars, 1)],
            ['分割設定（上限 / 重なり）', "{$report->chunkSize} / {$report->chunkOverlap}"],
            ['所要時間', number_format($report->elapsedMs / 1000, 2).' 秒'],
        ]);

        // DB 全体の件数（受け入れ条件の確認用: 候補者ごとの文書数と、埋め込みが空のチャンク数）
        $this->info('DB の状態');
        $this->table(['項目', '値'], [
            ['候補者', Candidate::count()],
            ['履歴書', Document::where('type', Document::TYPE_RESUME)->count()],
            ['職務経歴書', Document::where('type', Document::TYPE_CAREER_HISTORY)->count()],
            ['求人票', Document::where('type', Document::TYPE_JOB)->count()],
            ['チャンク', Chunk::count()],
            ['embedding が NULL のチャンク', Chunk::whereNull('embedding')->count()],
        ]);

        return self::SUCCESS;
    }
}
