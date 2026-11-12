<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersSearchResults;
use App\Models\Document;
use App\Services\Rag\ExternalApiException;
use App\Services\Rag\RagPipeline;
use App\Services\Rag\SearchOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 求人票に合う候補者を探す（求人マッチング。要件定義書 第4.2章）。
 *
 * 求人票の一部を OpenAI Embeddings API で1本のベクトルにし、候補者検索と同じ仕組みで並べる。
 * 実行ごとに queries に記録する（mode = job_match、source_document_id = 求人票）。
 */
class RagMatch extends Command
{
    use RendersSearchResults;

    protected $signature = 'rag:match
        {job_document_id : 求人票の文書 ID}
        {--source= : 検索に使う部分（required: 必須条件 / required_preferred: 必須＋歓迎条件 / full: 全文）。省略時は RAG_JOB_MATCH_SOURCE}
        {--doc-type= : 候補者の文書を種別で絞る（rirekisho: 履歴書 / shokumu: 職務経歴書）}
        {--score= : スコアの集約方法（max / avg3 / max_plus_hits）。省略時は RAG_CANDIDATE_SCORE}
        {--top-k= : 表示する候補者の数。省略時は RAG_TOP_K}
        {--limit= : 1段目で取得するチャンク数。省略時は RAG_CHUNK_LIMIT}
        {--min-similarity= : 類似度の下限。省略時は RAG_MIN_SIMILARITY}
        {--full : 検索に使ったテキストとチャンクの本文を省略せずに表示する}';

    protected $description = '求人票に合う候補者を、候補者検索と同じ仕組みで並べる';

    public function handle(RagPipeline $pipeline): int
    {
        $id = (string) $this->argument('job_document_id');
        $job = ctype_digit($id) ? Document::find((int) $id) : null;
        if ($job === null) {
            $this->error("文書が見つからない: id={$this->argument('job_document_id')}");

            return self::FAILURE;
        }

        $source = (string) ($this->option('source') ?? config('rag.search.job_match_source'));

        try {
            $options = SearchOptions::fromConfig([
                'chunk_limit' => $this->intOption('limit'),
                'top_k' => $this->intOption('top-k'),
                'min_similarity' => $this->floatOption('min-similarity'),
                'score' => $this->option('score'),
                'document_type' => $this->option('doc-type'),
            ]);
            // 設定の誤り・求人票でない・見出しがない・--source の誤りは、match() が API を呼ぶ前に例外にする
            $result = $pipeline->match($job, $source, $options);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ExternalApiException $e) {
            // 外部 API の失敗（RagPipeline がログにも出している）。記録はされない。
            // DB のエラーなど他の例外は捕まえない（「外部 API の失敗」と取り違えないように）
            $this->error('外部 API の呼び出しに失敗した: '.$e->getMessage());

            return self::FAILURE;
        }

        $full = (bool) $this->option('full');
        $this->line("求人票: [文書 {$job->id}] {$job->title}");
        $this->line("検索に使った部分: {$source}（".mb_strlen($result->searchText).' 文字）');
        $this->line('  '.($full ? $result->searchText : Str::limit(str_replace("\n", ' ', $result->searchText), 80)));
        $this->renderConditions('候補者', $options);
        $this->renderCandidates($result->ranking, $full);

        return $this->renderRecorded($result->queryLog?->id, $result->recordError);
    }
}
