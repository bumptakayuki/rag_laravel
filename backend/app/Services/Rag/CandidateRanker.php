<?php

namespace App\Services\Rag;

/**
 * チャンク検索の結果を候補者ごとにまとめ、スコア順に並べる（要件定義書 第4.2章）。
 *
 * 候補者そのものはベクトルを持たない。履歴書・職務経歴書のチャンクを持ち主の候補者でまとめ、
 * その中の類似度から候補者のスコアを決める。計算は GroupRanker と同じで、まとめるキーだけが違う。
 */
class CandidateRanker extends GroupRanker
{
    /** 候補者単位でまとめる。求人票のチャンク（candidate_id が null）は対象外 */
    protected function groupKey(RetrievedChunk $chunk): ?int
    {
        return $chunk->candidateId;
    }
}
