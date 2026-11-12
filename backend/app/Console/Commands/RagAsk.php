<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersSearchResults;
use App\Models\Candidate;
use App\Services\Rag\ExternalApiException;
use App\Services\Rag\Prompt;
use App\Services\Rag\PromptBuilder;
use App\Services\Rag\PromptSource;
use App\Services\Rag\RagPipeline;
use App\Services\Rag\SearchOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 質問に、候補者の資料だけを根拠に答える（要件定義書 第4.3章）。実行ごとに queries に記録する。
 *
 * 質問の埋め込みに OpenAI Embeddings API を1回、回答生成に Anthropic Messages API を1回呼ぶ
 * （検索で資料が1件もなければ Messages API は呼ばない）。
 */
class RagAsk extends Command
{
    use RendersSearchResults;

    protected $signature = 'rag:ask
        {question : 質問文（例: "PHPの経験がある人は？"）}
        {--doc-type= : 候補者の文書を種別で絞る（rirekisho: 履歴書 / shokumu: 職務経歴書）}
        {--score= : スコアの集約方法（max / avg3 / max_plus_hits）。省略時は RAG_CANDIDATE_SCORE}
        {--top-k= : プロンプトに入れる候補者の数。省略時は RAG_TOP_K}
        {--limit= : 1段目で取得するチャンク数。省略時は RAG_CHUNK_LIMIT}
        {--min-similarity= : 類似度の下限。省略時は RAG_MIN_SIMILARITY}
        {--chunks-per-candidate= : プロンプトに入れる候補者あたりのチャンク数。省略時は RAG_CHUNKS_PER_CANDIDATE}
        {--show-prompt : Claude に送ったプロンプト全文を表示する}
        {--full : チャンクの本文を省略せずに表示する}';

    protected $description = '候補者の資料を検索し、その資料だけを根拠に Claude が回答する（根拠は [番号] で示す）';

    public function handle(RagPipeline $pipeline): int
    {
        try {
            $options = SearchOptions::fromConfig([
                'chunk_limit' => $this->intOption('limit'),
                'top_k' => $this->intOption('top-k'),
                'min_similarity' => $this->floatOption('min-similarity'),
                'score' => $this->option('score'),
                'document_type' => $this->option('doc-type'),
            ]);
            $chunksPerCandidate = $this->intOption('chunks-per-candidate') ?? (int) config('rag.answer.chunks_per_candidate');
            $question = (string) $this->argument('question');

            $result = $pipeline->ask($question, $options, $chunksPerCandidate);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ExternalApiException $e) {
            // 外部 API の失敗（RagPipeline がログにも出している）。記録はされない。
            // DB のエラーなど他の例外は捕まえない（「外部 API の失敗」と取り違えないように）
            $this->error('外部 API の呼び出しに失敗した: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("質問: {$question}");
        $this->renderConditions('候補者', $options);
        $this->line("プロンプトに入れるチャンク: 候補者あたり最大 {$chunksPerCandidate} 件");
        $this->renderCandidates($result->ranking, (bool) $this->option('full'));

        if ($this->option('show-prompt')) {
            $this->info('--- 送信したプロンプト ---');
            $this->line($result->prompt->fullText());
            $this->newLine();
        }

        $this->info('--- 回答 ---');
        $this->line($result->answer);
        $this->newLine();

        $this->renderSources($result->prompt->sources, $result->answer);
        foreach ($result->unknownCitations as $number) {
            $this->warn("回答に資料にない番号 [{$number}] が出てきた（プロンプトに入れた資料は [1]〜[".count($result->prompt->sources).']）');
        }
        if ($result->prompt->sources === []) {
            $this->warn('資料が1件もなかったので、Claude を呼ばずに「'.PromptBuilder::NO_ANSWER.'」と答えた');
        }

        $this->line(sprintf(
            '所要時間(ms): %s',
            collect($result->latencyMs)->map(fn ($ms, $stage) => "{$stage}=".round($ms, 1))->implode(' / '),
        ));

        return $this->renderRecorded($result->queryLog?->id, $result->recordError);
    }

    /**
     * 資料番号の対応表。回答で使われた番号には * をつける
     *
     * @param  list<PromptSource>  $sources
     */
    private function renderSources(array $sources, string $answer): void
    {
        if ($sources === []) {
            return;
        }
        $cited = Prompt::citedNumbers($answer);
        $candidates = Candidate::whereIn('id', array_unique(array_map(fn ($s) => $s->candidateId, $sources)))->get()->keyBy('id');

        $this->info('--- 資料（* = 回答で使われた番号）---');
        foreach ($sources as $source) {
            $candidate = $candidates[$source->candidateId] ?? null;
            $this->line(sprintf(
                ' %s[%d] %s  %s #%d  %.4f  %s',
                in_array($source->number, $cited, true) ? '*' : ' ',
                $source->number,
                $candidate ? "{$candidate->code} {$candidate->name}" : "候補者 id={$source->candidateId}",
                PromptBuilder::sourceLabel($source->chunk),
                $source->chunk->chunkIndex,
                $source->chunk->similarity,
                Str::limit(str_replace("\n", ' ', $source->chunk->content), 40),
            ));
        }
        $this->newLine();
    }
}
