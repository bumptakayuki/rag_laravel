<?php

namespace App\Services\Rag;

/**
 * 集約した1グループ（候補者1名、または求人票1件）。
 */
final readonly class RankedGroup
{
    /**
     * @param  int  $key  候補者 ID（CandidateRanker）または文書 ID（GroupRanker）
     * @param  array<string, int>  $hitsByType  文書種別ごとのヒット数（例: ['rirekisho' => 1, 'shokumu' => 3]）
     * @param  list<RetrievedChunk>  $chunks  ヒットしたチャンク（類似度の高い順）
     */
    public function __construct(
        public int $key,
        public float $score,
        public float $maxSimilarity,
        public int $hits,
        public array $hitsByType,
        public array $chunks,
    ) {}
}
