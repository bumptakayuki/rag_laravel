<?php

namespace App\Services\Rag;

use App\Models\QueryLog;

/**
 * RagPipeline の1回分の結果。画面・コマンドはこれを表示するだけにする。
 *
 * 実行した直後は RagPipeline が、履歴（queries）から表示するときは QueryLogRestorer が作る。
 */
final readonly class RagResult
{
    /**
     * @param  string  $searchText  検索に使ったテキスト（質問文、または求人票から取り出した部分）
     * @param  Prompt|null  $prompt  回答を生成しないとき（rag:match）は null
     * @param  string|null  $answer  回答を生成しないとき（rag:match）は null
     * @param  list<int>  $unknownCitations  回答に出てきたが、プロンプトに入れていない資料番号（Claude の誤り。表示して気づけるようにする）
     * @param  array<string, float>  $latencyMs  工程ごとの所要時間（ミリ秒）
     * @param  QueryLog|null  $queryLog  記録した行。記録に失敗したときは null（理由は $recordError）
     * @param  string  $mode  QueryLog::MODE_*。job_search のときだけ $ranking のグループは求人票（文書 ID）
     * @param  array{documents: int, chunks: int}|null  $target  検索の対象になった文書数・チャンク数（古い記録には無い）
     * @param  array{dimensions: int, head: list<float>}|null  $embedding  質問ベクトルの次元数と先頭3つ（画面の「検索の流れ」用）
     * @param  int  $missingChunks  履歴から復元したとき、取り込み直しで消えていたチャンクの数
     */
    public function __construct(
        public ?QueryLog $queryLog,
        public string $searchText,
        public SearchOptions $options,
        public RankResult $ranking,
        public ?Prompt $prompt,
        public ?string $answer,
        public array $unknownCitations,
        public array $latencyMs,
        public ?string $recordError = null,
        public string $mode = QueryLog::MODE_QUESTION,
        public ?array $target = null,
        public ?array $embedding = null,
        public int $missingChunks = 0,
    ) {}

    /** 求人票を探した結果か（ランキングのグループが求人票） */
    public function isJobSearch(): bool
    {
        return $this->mode === QueryLog::MODE_JOB_SEARCH;
    }
}
