<?php

namespace App\Services\Rag;

use App\Models\Candidate;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\Contracts\Embedder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * 検索 → 生成 → 記録をつなぐ（要件定義書 第4.3章・第6章）。各工程の所要時間を測り、queries に記録する。
 *
 *   ask:   質問 → Embedder → Retriever → CandidateRanker → PromptBuilder → ClaudeClient → queries（mode = question）
 *   match: 求人票 → JobMatcher（検索に使う部分）→ Embedder → Retriever → CandidateRanker → queries（mode = job_match）
 *   searchJobs: 質問 → Embedder → Retriever（求人票）→ GroupRanker → queries（mode = job_search。質問画面の求人票モード）
 *
 * - 引数の誤りは外部 API を呼ぶ前に InvalidArgumentException にする
 * - 外部 API の失敗はログに出してから ExternalApiException で投げる（記録はしない。queries は「結果が出た実行」の履歴）
 * - 記録（queries への保存）の失敗では例外にしない。回答は API 費用をかけて作ったものなので捨てずに返し、
 *   RagResult::$recordError に理由を入れる（表示する側が回答を出してから警告する）
 */
class RagPipeline
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly Retriever $retriever,
        private readonly ClaudeClient $claude,
        private readonly JobMatcher $jobMatcher,
    ) {}

    /**
     * 質問から候補者を探し、資料（ヒットしたチャンク）だけを根拠に回答を生成する。
     *
     * 学習用の実験（要件定義書 第5章。RS-007）:
     * - $searchOnly: 検索だけ実行する。Claude を呼ばず、プロンプトも作らない（answer は null で記録する）
     * - $compareWithoutRag: 同じ質問を資料なしで Claude に送った回答も作り、answer_without_rag に記録する
     */
    public function ask(
        string $question,
        SearchOptions $options,
        int $chunksPerCandidate,
        bool $searchOnly = false,
        bool $compareWithoutRag = false,
    ): RagResult {
        if (trim($question) === '') {
            throw new InvalidArgumentException('質問が空');
        }
        if ($searchOnly && $compareWithoutRag) {
            throw new InvalidArgumentException('検索だけ実行するときは、RAGなし比較（Claude の呼び出し）はできない');
        }
        $builder = new PromptBuilder($chunksPerCandidate);

        [$chunks, $ranking, $latency, $info] = $this->search($question, $options, Retriever::SCOPE_CANDIDATE);

        $prompt = null;
        $answer = null;
        $answerWithoutRag = null;
        $unknown = [];
        if (! $searchOnly) {
            $started = hrtime(true);
            $prompt = $builder->build($question, $ranking, $this->candidateLabels($ranking));
            $latency['prompt'] = self::elapsedMs($started);

            // 資料が1件もなければ、Claude に聞いても答えは決まっている。API を呼ばずに決まり文句を返す
            $started = hrtime(true);
            $answer = $prompt->sources === []
                ? PromptBuilder::NO_ANSWER
                : $this->callExternal('generate', fn () => $this->claude->generateText($prompt->system, $prompt->user));
            $latency['generate'] = self::elapsedMs($started);

            $unknown = array_values(array_filter(Prompt::citedNumbers($answer), fn (int $n) => $prompt->source($n) === null));
        }

        if ($compareWithoutRag) {
            // 資料を渡さず、質問だけを送る（比較用）。資料が0件でも呼ぶ（「資料なしなら何と答えるか」を見るため）
            $started = hrtime(true);
            $answerWithoutRag = $this->callExternal(
                'generate_without_rag',
                fn () => $this->claude->generateText(PromptBuilder::SYSTEM_WITHOUT_RAG, $question),
            );
            $latency['generate_without_rag'] = self::elapsedMs($started);
        }

        $params = [...$options->toArray(), 'chunks_per_candidate' => $chunksPerCandidate];
        // 実験の指定は付けたときだけ記録する（それ以前の記録と同じ形を保つ）
        $params += array_filter(['search_only' => $searchOnly, 'compare_without_rag' => $compareWithoutRag]);

        [$log, $recordError] = $this->record(
            mode: QueryLog::MODE_QUESTION,
            question: $question,
            params: $params,
            chunks: $chunks,
            ranking: $ranking,
            latency: $latency,
            info: $info,
            prompt: $prompt,
            answer: $answer,
            answerWithoutRag: $answerWithoutRag,
        );

        return new RagResult(
            $log, $question, $options, $ranking, $prompt, $answer, $unknown, $latency, $recordError,
            mode: QueryLog::MODE_QUESTION, target: $info['target'], embedding: $info['embedding'],
            searchOnly: $searchOnly, answerWithoutRag: $answerWithoutRag,
        );
    }

    /**
     * 求人票に合う候補者を探す（回答は生成しない）。
     */
    public function match(Document $job, string $source, SearchOptions $options): RagResult
    {
        // 求人票でない・見出しがない・source の誤りはここで例外になる（API を呼ぶ前）
        $text = $this->jobMatcher->sourceText($job, $source);

        [$chunks, $ranking, $latency, $info] = $this->search($text, $options, Retriever::SCOPE_CANDIDATE);

        [$log, $recordError] = $this->record(
            mode: QueryLog::MODE_JOB_MATCH,
            question: $text,
            params: [...$options->toArray(), 'source' => $source],
            chunks: $chunks,
            ranking: $ranking,
            latency: $latency,
            info: $info,
            sourceDocumentId: $job->id,
        );

        return new RagResult(
            $log, $text, $options, $ranking, null, null, [], $latency, $recordError,
            mode: QueryLog::MODE_JOB_MATCH, target: $info['target'], embedding: $info['embedding'],
        );
    }

    /**
     * 質問に近い求人票を探す（回答は生成しない。質問画面の求人票モード）。
     */
    public function searchJobs(string $question, SearchOptions $options): RagResult
    {
        if (trim($question) === '') {
            throw new InvalidArgumentException('質問が空');
        }

        [$chunks, $ranking, $latency, $info] = $this->search($question, $options, Retriever::SCOPE_JOB);

        [$log, $recordError] = $this->record(
            mode: QueryLog::MODE_JOB_SEARCH,
            question: $question,
            params: $options->toArray(),
            chunks: $chunks,
            ranking: $ranking,
            latency: $latency,
            info: $info,
        );

        return new RagResult(
            $log, $question, $options, $ranking, null, null, [], $latency, $recordError,
            mode: QueryLog::MODE_JOB_SEARCH, target: $info['target'], embedding: $info['embedding'],
        );
    }

    /**
     * 検索（埋め込み → チャンク検索 → 候補者 / 求人票ごとに集約）。工程ごとの時間も返す。
     *
     * 4つ目の要素は画面の「検索の流れ」に出す補足（対象の文書数・チャンク数、質問ベクトルの次元数と先頭3つ）
     *
     * @return array{0: list<RetrievedChunk>, 1: RankResult, 2: array<string, float>, 3: array{target: array{documents: int, chunks: int}, embedding: array{dimensions: int, head: list<float>}}}
     */
    private function search(string $text, SearchOptions $options, string $scope): array
    {
        // 設定の誤り（スコア方法・文書種別など）は埋め込み API を呼ぶ前に止める
        $ranker = $scope === Retriever::SCOPE_JOB ? $options->documentRanker() : $options->candidateRanker();
        $this->retriever->validate($scope, $options->chunkLimit, $options->documentType);

        $started = hrtime(true);
        $vector = $this->callExternal('embed', fn () => $this->embedder->embed([$text])[0]);
        $latency = ['embed' => self::elapsedMs($started)];

        $started = hrtime(true);
        $chunks = $this->retriever->retrieveByVector($vector, $scope, $options->chunkLimit, $options->documentType);
        $latency['search'] = self::elapsedMs($started);

        $started = hrtime(true);
        $ranking = $ranker->rank($chunks);
        $latency['rank'] = self::elapsedMs($started);

        // 件数を数える時間は工程の時間に入れない（検索そのものの時間ではないため）
        $info = [
            'target' => $this->retriever->countTargets($scope, $options->documentType),
            'embedding' => [
                'dimensions' => count($vector),
                'head' => array_map(fn ($v) => round((float) $v, 6), array_slice($vector, 0, 3)),
            ],
        ];

        return [$chunks, $ranking, $latency, $info];
    }

    /**
     * 外部 API を呼ぶ。失敗したら、どの工程で何が起きたかをログに出し、ExternalApiException で包んで投げる（要件定義書 第7章）。
     * API キーや送った本文はログに出さない（例外メッセージにはステータスコードと API のエラー文だけが入る）
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function callExternal(string $stage, callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Log::error('RAG の外部 API 呼び出しに失敗した', ['stage' => $stage, 'error' => $e->getMessage()]);

            throw new ExternalApiException($stage, $e);
        }
    }

    /**
     * queries に1行記録する。失敗したら [null, 理由] を返す（ログにも出す）。
     *
     * 保存は DB::transaction で包む。呼び出し元がトランザクション中（テストの RefreshDatabase など）でも
     * セーブポイントまで戻すだけで済み、続く読み込みが「トランザクションが中断された」で失敗しない
     *
     * retrieved には「1段目で取れたチャンク」「上位 top_k 名（job_search は top_k 件の求人票）の集約結果」「件数」
     * 「対象の文書数・チャンク数」「質問ベクトルの次元数と先頭3つ」と、回答を生成したときは
     * 「資料番号 → チャンク」の対応を入れる（後から、どの資料を見てどう答えたかを追えるようにする）。
     * 履歴の表示（QueryLogRestorer）はこの中身から画面を組み立て直す
     *
     * @param  array{target: array{documents: int, chunks: int}, embedding: array{dimensions: int, head: list<float>}}  $info
     * @param  array<string, mixed>  $params
     * @param  list<RetrievedChunk>  $chunks  1段目で取れたチャンク（下限で除外する前）
     * @param  array<string, float>  $latency
     * @return array{0: QueryLog|null, 1: string|null} 記録した行と、失敗したときの理由
     */
    private function record(
        string $mode,
        string $question,
        array $params,
        array $chunks,
        RankResult $ranking,
        array $latency,
        array $info,
        ?Prompt $prompt = null,
        ?string $answer = null,
        ?int $sourceDocumentId = null,
        ?string $answerWithoutRag = null,
    ): array {
        $latency['total'] = array_sum($latency);

        $retrieved = [
            'counts' => [
                'retrieved' => $ranking->retrieved,
                'kept' => $ranking->kept,
                'total_groups' => $ranking->totalGroups,
            ],
            // 1段目で取れたチャンク（類似度の高い順。下限で除外する前）
            'chunks' => array_map(fn (RetrievedChunk $c) => [
                'chunk_id' => $c->chunkId,
                'document_id' => $c->documentId,
                'candidate_id' => $c->candidateId,
                'similarity' => round($c->similarity, 6),
            ], $chunks),
            'target' => $info['target'],
            'embedding' => $info['embedding'],
        ];
        // 集約結果。求人票を探したときは文書 ID、それ以外は候補者 ID でまとめている
        [$groupsKey, $idKey] = $mode === QueryLog::MODE_JOB_SEARCH ? ['jobs', 'document_id'] : ['candidates', 'candidate_id'];
        $retrieved[$groupsKey] = array_map(fn (RankedGroup $g) => [
            $idKey => $g->key,
            'score' => round($g->score, 6),
            'max_similarity' => round($g->maxSimilarity, 6),
            'hits' => $g->hits,
            'hits_by_type' => $g->hitsByType,
        ], $ranking->groups);
        if ($prompt !== null) {
            $retrieved['sources'] = array_map(fn (PromptSource $s) => [
                'number' => $s->number,
                'chunk_id' => $s->chunk->chunkId,
                'candidate_id' => $s->candidateId,
            ], $prompt->sources);
        }

        try {
            $log = DB::transaction(fn () => QueryLog::create([
                'mode' => $mode,
                'source_document_id' => $sourceDocumentId,
                'question' => $question,
                'params' => $params,
                'retrieved' => $retrieved,
                'prompt' => $prompt?->fullText(),
                'answer' => $answer,
                'answer_without_rag' => $answerWithoutRag,
                'latency_ms' => array_map(fn (float $ms) => round($ms, 2), $latency),
            ]));
        } catch (QueryException $e) {
            // 理由は PDO のメッセージの1行目（SQLSTATE とエラーの種類）だけにする。
            // QueryException のメッセージには SQL がバインド値ごと入り、PDO のメッセージも制約違反では
            // 2行目以降（DETAIL: Failing row contains (...)）に行の値が入る。どちらもプロンプト・回答の全文を含む
            $error = strtok($e->getPrevious()?->getMessage() ?? $e->getMessage(), "\n");
            Log::error('RAG の実行結果を queries に記録できなかった', ['mode' => $mode, 'error' => $error]);

            return [null, $error];
        }

        return [$log, null];
    }

    /** @return array<int, string> 候補者 ID => "C001 山田 太郎" */
    private function candidateLabels(RankResult $ranking): array
    {
        return Candidate::whereIn('id', array_map(fn (RankedGroup $g) => $g->key, $ranking->groups))
            ->get()
            ->mapWithKeys(fn (Candidate $c) => [$c->id => "{$c->code} {$c->name}"])
            ->all();
    }

    private static function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1e6;
    }
}
