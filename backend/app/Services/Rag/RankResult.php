<?php

namespace App\Services\Rag;

/**
 * 2段目（集約）の結果。上位 top_k のグループと、途中の件数（集約の過程を追うため）を持つ。
 */
final readonly class RankResult
{
    /**
     * @param  list<RankedGroup>  $groups  スコア順の上位 top_k
     * @param  int  $retrieved  1段目で取れたチャンク数
     * @param  int  $kept  下限で除外した後に残ったチャンク数
     * @param  int  $totalGroups  集約後のグループ数（top_k で切る前）
     */
    public function __construct(
        public array $groups,
        public int $retrieved,
        public int $kept,
        public int $totalGroups,
    ) {}
}
