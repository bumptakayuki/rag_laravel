<?php

namespace App\Services\Rag;

/**
 * 求人マッチングの結果。検索に使ったテキスト（RS-005 で queries.question に記録する）と候補者の順位。
 */
final readonly class JobMatchResult
{
    public function __construct(
        public string $text,
        public RankResult $ranking,
    ) {}
}
