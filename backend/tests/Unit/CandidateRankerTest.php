<?php

namespace Tests\Unit;

use App\Services\Rag\CandidateRanker;
use App\Services\Rag\GroupRanker;
use App\Services\Rag\RetrievedChunk;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CandidateRanker / GroupRanker の単体テスト（DB を使わない純粋な計算）。
 */
class CandidateRankerTest extends TestCase
{
    private int $nextChunkId = 1;

    /** テスト用のチャンク。documentId は「候補者 × 種別」で決まる値にしておく */
    private function chunk(?int $candidateId, float $similarity, string $type = 'shokumu', ?int $documentId = null): RetrievedChunk
    {
        return new RetrievedChunk(
            chunkId: $this->nextChunkId++,
            documentId: $documentId ?? (($candidateId ?? 0) * 10 + ($type === 'rirekisho' ? 1 : 2)),
            candidateId: $candidateId,
            documentType: $type,
            chunkIndex: 0,
            section: 'スキル',
            content: 'テスト',
            similarity: $similarity,
        );
    }

    /**
     * 候補者 1: 0.80 / 0.50 / 0.50 / 0.50（最大は高いが他は低い）
     * 候補者 2: 0.75 / 0.74 / 0.73（全体に高い）
     * 候補者 3: 0.78 だけ
     *
     * @return list<RetrievedChunk>
     */
    private function sample(): array
    {
        return [
            $this->chunk(1, 0.80),
            $this->chunk(1, 0.50, 'rirekisho'),
            $this->chunk(1, 0.50),
            $this->chunk(1, 0.50),
            $this->chunk(2, 0.75),
            $this->chunk(2, 0.74),
            $this->chunk(2, 0.73, 'rirekisho'),
            $this->chunk(3, 0.78),
        ];
    }

    /** @return list<int> 候補者 ID の並び */
    private function order(GroupRanker $ranker, array $chunks): array
    {
        return array_map(fn ($g) => $g->key, $ranker->rank($chunks)->groups);
    }

    public function test_max_は最大類似度をスコアにする(): void
    {
        $result = (new CandidateRanker(GroupRanker::SCORE_MAX))->rank($this->sample());

        $this->assertSame([1, 3, 2], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertEqualsWithDelta(0.80, $result->groups[0]->score, 1e-9);
        $this->assertEqualsWithDelta(0.78, $result->groups[1]->score, 1e-9);
        $this->assertEqualsWithDelta(0.75, $result->groups[2]->score, 1e-9);
    }

    public function test_avg3_は上位3チャンクの平均をスコアにする(): void
    {
        $result = (new CandidateRanker(GroupRanker::SCORE_AVG3))->rank($this->sample());

        // 候補者 2: (0.75+0.74+0.73)/3 = 0.74、候補者 3: 1件だけなので 0.78、候補者 1: (0.80+0.50+0.50)/3 = 0.60
        $this->assertSame([3, 2, 1], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertEqualsWithDelta(0.78, $result->groups[0]->score, 1e-9);
        $this->assertEqualsWithDelta(0.74, $result->groups[1]->score, 1e-9);
        $this->assertEqualsWithDelta(0.60, $result->groups[2]->score, 1e-9);
    }

    public function test_avg3_はヒットが3件未満ならある分だけで平均する(): void
    {
        $result = (new CandidateRanker(GroupRanker::SCORE_AVG3))->rank([
            $this->chunk(1, 0.80),
            $this->chunk(1, 0.60),
        ]);

        $this->assertEqualsWithDelta(0.70, $result->groups[0]->score, 1e-9);
    }

    public function test_max_plus_hits_は2件目以降のヒット数で加点する(): void
    {
        $result = (new CandidateRanker(GroupRanker::SCORE_MAX_PLUS_HITS, hitBonus: 0.01))->rank($this->sample());

        // 候補者 1: 0.80 + 0.01×3 = 0.83、候補者 3: 0.78 + 0、候補者 2: 0.75 + 0.01×2 = 0.77
        $this->assertSame([1, 3, 2], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertEqualsWithDelta(0.83, $result->groups[0]->score, 1e-9);
        $this->assertEqualsWithDelta(0.78, $result->groups[1]->score, 1e-9);
        $this->assertEqualsWithDelta(0.77, $result->groups[2]->score, 1e-9);
        // 加点しても最大類似度そのものは変わらない
        $this->assertEqualsWithDelta(0.80, $result->groups[0]->maxSimilarity, 1e-9);
    }

    public function test_max_plus_hits_の加点には上限がある(): void
    {
        $chunks = [$this->chunk(1, 0.70)];
        for ($i = 0; $i < 9; $i++) {
            $chunks[] = $this->chunk(1, 0.40);
        }

        $result = (new CandidateRanker(GroupRanker::SCORE_MAX_PLUS_HITS, hitBonus: 0.01, hitBonusMaxHits: 5))->rank($chunks);

        // 2件目以降は9件あるが、加点は5件分（0.05）まで
        $this->assertEqualsWithDelta(0.75, $result->groups[0]->score, 1e-9);
        $this->assertSame(10, $result->groups[0]->hits);
    }

    public function test_集約方法で順位が変わる(): void
    {
        $this->assertSame([1, 3, 2], $this->order(new CandidateRanker(GroupRanker::SCORE_MAX), $this->sample()));
        $this->assertSame([3, 2, 1], $this->order(new CandidateRanker(GroupRanker::SCORE_AVG3), $this->sample()));
        // 加点を大きくすると、ヒットの多い候補者 2 が候補者 3 を抜く
        $this->assertSame([1, 2, 3], $this->order(new CandidateRanker(GroupRanker::SCORE_MAX_PLUS_HITS, hitBonus: 0.05), $this->sample()));
    }

    public function test_ヒット数_文書種別ごとのヒット数_チャンクの並びを返す(): void
    {
        $result = (new CandidateRanker)->rank($this->sample());
        $first = $result->groups[0];

        $this->assertSame(4, $first->hits);
        $this->assertSame(['shokumu' => 3, 'rirekisho' => 1], $first->hitsByType);

        // チャンクは類似度の高い順。同じ類似度ならチャンク ID の小さい順
        $similarities = array_map(fn ($c) => $c->similarity, $first->chunks);
        $this->assertSame([0.80, 0.50, 0.50, 0.50], $similarities);
        $ids = array_map(fn ($c) => $c->chunkId, array_slice($first->chunks, 1));
        $this->assertSame([2, 3, 4], $ids);
    }

    public function test_下限未満のチャンクは集約の前に捨てる(): void
    {
        $result = (new CandidateRanker(minSimilarity: 0.76))->rank($this->sample());

        // 残るのは 0.80（候補者 1）と 0.78（候補者 3）だけ
        $this->assertSame(8, $result->retrieved);
        $this->assertSame(2, $result->kept);
        $this->assertSame([1, 3], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertSame(1, $result->groups[0]->hits); // 0.50 の3件は数えない
    }

    public function test_top_k_で切る前のグループ数も返す(): void
    {
        $result = (new CandidateRanker(topK: 2))->rank($this->sample());

        $this->assertCount(2, $result->groups);
        $this->assertSame(3, $result->totalGroups);
    }

    public function test_同点ならヒット数の多い順_それも同じなら_id_の小さい順(): void
    {
        $result = (new CandidateRanker)->rank([
            $this->chunk(5, 0.70),
            $this->chunk(4, 0.70),
            $this->chunk(6, 0.70),
            $this->chunk(6, 0.30),
        ]);

        $this->assertSame([6, 4, 5], array_map(fn ($g) => $g->key, $result->groups));
    }

    public function test_候補者単位では求人票のチャンクを数えない(): void
    {
        $result = (new CandidateRanker)->rank([
            $this->chunk(null, 0.95, 'job', documentId: 99),
            $this->chunk(1, 0.60),
        ]);

        $this->assertSame([1], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertSame(1, $result->totalGroups);
    }

    public function test_group_ranker_は文書単位でまとめる(): void
    {
        $result = (new GroupRanker)->rank([
            $this->chunk(null, 0.60, 'job', documentId: 10),
            $this->chunk(null, 0.70, 'job', documentId: 20),
            $this->chunk(null, 0.65, 'job', documentId: 10),
        ]);

        $this->assertSame([20, 10], array_map(fn ($g) => $g->key, $result->groups));
        $this->assertSame(2, $result->groups[1]->hits);
        $this->assertSame(['job' => 2], $result->groups[1]->hitsByType);
    }

    public function test_チャンクがなければ空の結果を返す(): void
    {
        $result = (new CandidateRanker)->rank([]);

        $this->assertSame([], $result->groups);
        $this->assertSame(0, $result->retrieved);
        $this->assertSame(0, $result->totalGroups);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidArguments(): array
    {
        return [
            '集約方法が不正' => [['method' => 'sum']],
            'top_k が0' => [['topK' => 0]],
            '加点の上限が負' => [['hitBonusMaxHits' => -1]],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_不正な設定は例外にする(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CandidateRanker(...$arguments);
    }
}
