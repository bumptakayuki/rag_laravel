<?php

namespace App\Services\Rag;

use InvalidArgumentException;

/**
 * 1回の検索で使う設定。config/rag.php の search の値を初期値にし、コマンドのオプションで上書きする。
 *
 * Ranker 自体は設定を読まない純粋なクラスなので、ここで値を集めて渡す。
 */
final readonly class SearchOptions
{
    /** 1段目で取得するチャンク数の上限（全件を PHP に読み込むような指定を防ぐ。docs/architecture.md） */
    public const MAX_CHUNK_LIMIT = 1000;

    public function __construct(
        public int $chunkLimit,
        public int $topK,
        public float $minSimilarity,
        public string $scoreMethod,
        public float $hitBonus,
        public int $hitBonusMaxHits,
        /** 候補者の文書を種別で絞る（rirekisho / shokumu）。null はすべて */
        public ?string $documentType = null,
    ) {
        if ($chunkLimit < 1 || $chunkLimit > self::MAX_CHUNK_LIMIT) {
            throw new InvalidArgumentException(sprintf('chunk_limit は1〜%d にすること: %d', self::MAX_CHUNK_LIMIT, $chunkLimit));
        }
    }

    /**
     * @param  array{chunk_limit?: int, top_k?: int, min_similarity?: float, score?: string, document_type?: string|null}  $overrides
     *                                                                                                                                 null の値は「指定なし」（設定の値を使う）
     */
    public static function fromConfig(array $overrides = []): self
    {
        $config = config('rag.search');

        return new self(
            chunkLimit: (int) ($overrides['chunk_limit'] ?? $config['chunk_limit']),
            topK: (int) ($overrides['top_k'] ?? $config['top_k']),
            minSimilarity: (float) ($overrides['min_similarity'] ?? $config['min_similarity']),
            scoreMethod: (string) ($overrides['score'] ?? $config['candidate_score']),
            hitBonus: (float) $config['hit_bonus'],
            hitBonusMaxHits: (int) $config['hit_bonus_max_hits'],
            documentType: $overrides['document_type'] ?? null,
        );
    }

    /** top_k だけを変えた設定（rag:eval で、上位 k 名に入らなかった人の順位も知るために全員を並べる） */
    public function withTopK(int $topK): self
    {
        return new self($this->chunkLimit, $topK, $this->minSimilarity, $this->scoreMethod, $this->hitBonus, $this->hitBonusMaxHits, $this->documentType);
    }

    /** 候補者単位でまとめる Ranker */
    public function candidateRanker(): CandidateRanker
    {
        return new CandidateRanker($this->scoreMethod, $this->topK, $this->minSimilarity, $this->hitBonus, $this->hitBonusMaxHits);
    }

    /** 文書（求人票）単位でまとめる Ranker */
    public function documentRanker(): GroupRanker
    {
        return new GroupRanker($this->scoreMethod, $this->topK, $this->minSimilarity, $this->hitBonus, $this->hitBonusMaxHits);
    }

    /** 実行時の設定（画面・ログに出す。RS-005 で queries.params に入れる） */
    public function toArray(): array
    {
        return [
            'chunk_limit' => $this->chunkLimit,
            'top_k' => $this->topK,
            'min_similarity' => $this->minSimilarity,
            'score' => $this->scoreMethod,
            'document_type' => $this->documentType,
        ];
    }
}
