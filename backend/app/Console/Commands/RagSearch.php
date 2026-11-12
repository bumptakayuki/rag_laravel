<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersSearchResults;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * 自然文で候補者（または求人票）を検索する（要件定義書 第4.2章）。回答生成はしない（RS-005 の rag:ask）。
 *
 * 質問の埋め込みに OpenAI Embeddings API を1回呼ぶ。
 */
class RagSearch extends Command
{
    use RendersSearchResults;

    protected $signature = 'rag:search
        {question : 質問文（例: "PHPの経験がある人"）}
        {--type=candidate : 検索対象（candidate: 候補者 / job: 求人票）}
        {--doc-type= : 候補者の文書を種別で絞る（rirekisho: 履歴書 / shokumu: 職務経歴書）}
        {--score= : スコアの集約方法（max / avg3 / max_plus_hits）。省略時は RAG_CANDIDATE_SCORE}
        {--top-k= : 表示する候補者（求人票）の数。省略時は RAG_TOP_K}
        {--limit= : 1段目で取得するチャンク数。省略時は RAG_CHUNK_LIMIT}
        {--min-similarity= : 類似度の下限。省略時は RAG_MIN_SIMILARITY}
        {--full : チャンクの本文を省略せずに表示する}';

    protected $description = '自然文で候補者（または求人票）を検索し、ヒットしたチャンクを類似度つきで表示する';

    public function handle(Retriever $retriever): int
    {
        try {
            $type = (string) $this->option('type');
            $scope = match ($type) {
                'candidate' => Retriever::SCOPE_CANDIDATE,
                'job' => Retriever::SCOPE_JOB,
                default => throw new InvalidArgumentException("--type は candidate か job: {$type}"),
            };

            $options = SearchOptions::fromConfig([
                'chunk_limit' => $this->intOption('limit'),
                'top_k' => $this->intOption('top-k'),
                'min_similarity' => $this->floatOption('min-similarity'),
                'score' => $this->option('score'),
                'document_type' => $this->option('doc-type'),
            ]);

            // Ranker を先に作り、設定の誤り（スコア方法など）は API を呼ぶ前に止める
            $ranker = $scope === Retriever::SCOPE_JOB ? $options->documentRanker() : $options->candidateRanker();
            $question = (string) $this->argument('question');
            $chunks = $retriever->retrieve($question, $scope, $options->chunkLimit, $options->documentType);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $ranker->rank($chunks);

        $this->line("質問: {$question}");
        $this->renderConditions($scope === Retriever::SCOPE_JOB ? '求人票' : '候補者', $options);

        $full = (bool) $this->option('full');
        $scope === Retriever::SCOPE_JOB ? $this->renderJobs($result, $full) : $this->renderCandidates($result, $full);

        return self::SUCCESS;
    }
}
