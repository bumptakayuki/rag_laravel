<?php

namespace App\Services\Rag;

use InvalidArgumentException;

/**
 * 検索の2段目: チャンクをグループごとにまとめ、スコアを付けて並べる（要件定義書 第4.2章・第5章）。
 *
 * このクラスは文書（document_id）単位でまとめる。求人票の検索で使う。
 * 候補者単位でまとめる CandidateRanker は、まとめるキーだけを差し替えたサブクラス。
 * DB や設定を読まない純粋なクラスなので、単体テストで計算を確かめられる。
 *
 * 手順:
 *   1. 類似度が下限（minSimilarity）未満のチャンクを捨てる
 *   2. グループ（候補者・文書）ごとにまとめる
 *   3. グループのスコアを計算する（method）
 *   4. スコアの高い順に並べ、上位 topK を返す
 */
class GroupRanker
{
    /** 最大類似度（一番近いチャンクの値） */
    public const SCORE_MAX = 'max';

    /** 上位3チャンクの類似度の平均（ヒットが3件未満なら、ある分だけの平均） */
    public const SCORE_AVG3 = 'avg3';

    /** 最大類似度 + hitBonus × min(ヒット数 − 1, hitBonusMaxHits) */
    public const SCORE_MAX_PLUS_HITS = 'max_plus_hits';

    public const SCORE_METHODS = [self::SCORE_MAX, self::SCORE_AVG3, self::SCORE_MAX_PLUS_HITS];

    public function __construct(
        private readonly string $method = self::SCORE_MAX,
        private readonly int $topK = 5,
        private readonly float $minSimilarity = 0.0,
        private readonly float $hitBonus = 0.01,
        private readonly int $hitBonusMaxHits = 5,
    ) {
        if (! in_array($method, self::SCORE_METHODS, true)) {
            throw new InvalidArgumentException(sprintf('スコアの集約方法が不正: %s（%s のどれか）', $method, implode(' / ', self::SCORE_METHODS)));
        }
        if ($topK < 1) {
            throw new InvalidArgumentException("top_k は1以上にすること: {$topK}");
        }
        if ($hitBonusMaxHits < 0) {
            throw new InvalidArgumentException("hit_bonus_max_hits は0以上にすること: {$hitBonusMaxHits}");
        }
    }

    /**
     * @param  list<RetrievedChunk>  $chunks  1段目の結果（並び順は問わない）
     */
    public function rank(array $chunks): RankResult
    {
        // 1. 下限で除外
        $kept = array_values(array_filter($chunks, fn (RetrievedChunk $c) => $c->similarity >= $this->minSimilarity));

        // 2. グループごとにまとめる
        /** @var array<int, list<RetrievedChunk>> $grouped */
        $grouped = [];
        foreach ($kept as $chunk) {
            $key = $this->groupKey($chunk);
            if ($key === null) {
                continue; // まとめ先のないチャンク（候補者単位なのに求人票のチャンク等）は数えない
            }
            $grouped[$key][] = $chunk;
        }

        // 3. スコアを計算する
        $groups = [];
        foreach ($grouped as $key => $members) {
            // 同じ類似度ならチャンク ID の小さい順（結果を毎回同じにするため）
            usort($members, fn (RetrievedChunk $a, RetrievedChunk $b) => [$b->similarity, $a->chunkId] <=> [$a->similarity, $b->chunkId]);

            $hitsByType = [];
            foreach ($members as $chunk) {
                $hitsByType[$chunk->documentType] = ($hitsByType[$chunk->documentType] ?? 0) + 1;
            }

            $groups[] = new RankedGroup(
                key: $key,
                score: $this->score($members),
                maxSimilarity: $members[0]->similarity,
                hits: count($members),
                hitsByType: $hitsByType,
                chunks: $members,
            );
        }

        // 4. スコアの高い順。同点ならヒット数の多い順、それも同じならキーの小さい順
        usort($groups, fn (RankedGroup $a, RankedGroup $b) => [$b->score, $b->hits, $a->key] <=> [$a->score, $a->hits, $b->key]);

        return new RankResult(
            groups: array_slice($groups, 0, $this->topK),
            retrieved: count($chunks),
            kept: count($kept),
            totalGroups: count($groups),
        );
    }

    /** まとめる単位。このクラスは文書単位（求人票1件 = 1グループ） */
    protected function groupKey(RetrievedChunk $chunk): ?int
    {
        return $chunk->documentId;
    }

    /**
     * @param  non-empty-list<RetrievedChunk>  $members  類似度の高い順
     */
    private function score(array $members): float
    {
        $max = $members[0]->similarity;

        return match ($this->method) {
            self::SCORE_MAX => $max,
            self::SCORE_AVG3 => $this->average(array_slice($members, 0, 3)),
            // 1件目は max に含まれているので、加点は2件目から数える
            self::SCORE_MAX_PLUS_HITS => $max + $this->hitBonus * min(count($members) - 1, $this->hitBonusMaxHits),
        };
    }

    /** @param  non-empty-list<RetrievedChunk>  $chunks */
    private function average(array $chunks): float
    {
        return array_sum(array_map(fn (RetrievedChunk $c) => $c->similarity, $chunks)) / count($chunks);
    }
}
