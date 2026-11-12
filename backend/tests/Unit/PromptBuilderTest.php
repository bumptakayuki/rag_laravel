<?php

namespace Tests\Unit;

use App\Services\Rag\Prompt;
use App\Services\Rag\PromptBuilder;
use App\Services\Rag\RankedGroup;
use App\Services\Rag\RankResult;
use App\Services\Rag\RetrievedChunk;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * PromptBuilder の単体テスト（DB・外部 API を使わない）。
 */
class PromptBuilderTest extends TestCase
{
    private int $nextChunkId = 1;

    private function chunk(int $candidateId, float $similarity, string $content, string $type = 'shokumu', ?string $section = 'スキル'): RetrievedChunk
    {
        return new RetrievedChunk(
            chunkId: $this->nextChunkId++,
            documentId: $candidateId * 10,
            candidateId: $candidateId,
            documentType: $type,
            chunkIndex: 0,
            section: $section,
            content: $content,
            similarity: $similarity,
        );
    }

    /** @param  list<RetrievedChunk>  $chunks  類似度の高い順 */
    private function group(int $candidateId, array $chunks): RankedGroup
    {
        return new RankedGroup($candidateId, $chunks[0]->similarity, $chunks[0]->similarity, count($chunks), [], $chunks);
    }

    /** @param  list<RankedGroup>  $groups */
    private function ranking(array $groups): RankResult
    {
        return new RankResult($groups, 10, 8, count($groups));
    }

    public function test_チャンクに通し番号を振り_種別と見出しをつけて候補者ごとにまとめる(): void
    {
        $ranking = $this->ranking([
            $this->group(1, [
                $this->chunk(1, 0.8, 'PHP で8年開発した', 'shokumu', '職務経歴'),
                $this->chunk(1, 0.6, '情報工学科を卒業', 'rirekisho', '学歴'),
            ]),
            $this->group(2, [$this->chunk(2, 0.7, "PHP の\nフレームワーク", 'shokumu', null)]),
        ]);

        $prompt = (new PromptBuilder(3))->build('  PHPの経験がある人は？ ', $ranking, [1 => 'C001 架空 一郎', 2 => 'C002 架空 二郎']);

        $this->assertSame(<<<'TEXT'
            # 資料

            ## 候補者 C001 架空 一郎
            [1] (職務経歴書・職務経歴) PHP で8年開発した
            [2] (履歴書・学歴) 情報工学科を卒業

            ## 候補者 C002 架空 二郎
            [3] (職務経歴書) PHP の
            フレームワーク

            # 質問

            PHPの経験がある人は？
            TEXT, $prompt->user);

        // 番号 → 候補者・チャンクの対応表
        $this->assertSame([1, 2, 3], array_map(fn ($s) => $s->number, $prompt->sources));
        $this->assertSame([1, 1, 2], array_map(fn ($s) => $s->candidateId, $prompt->sources));
        $this->assertSame('情報工学科を卒業', $prompt->source(2)->chunk->content);
        $this->assertNull($prompt->source(4));
        $this->assertNull($prompt->source(0));
    }

    public function test_候補者あたりのチャンク数を_chunks_per_candidate_で切る(): void
    {
        $ranking = $this->ranking([
            $this->group(1, [$this->chunk(1, 0.9, 'a1'), $this->chunk(1, 0.8, 'a2'), $this->chunk(1, 0.7, 'a3')]),
            $this->group(2, [$this->chunk(2, 0.6, 'b1'), $this->chunk(2, 0.5, 'b2')]),
        ]);

        $prompt = (new PromptBuilder(2))->build('質問', $ranking);

        // 類似度の高い順に2件ずつ。番号は候補者をまたいで通しで振る
        $this->assertSame(['a1', 'a2', 'b1', 'b2'], array_map(fn ($s) => $s->chunk->content, $prompt->sources));
        $this->assertStringContainsString('[3] (職務経歴書・スキル) b1', $prompt->user);
        $this->assertStringNotContainsString('a3', $prompt->user);
        // 表示名を渡さない候補者は ID で書く
        $this->assertStringContainsString('## 候補者 候補者 id=2', $prompt->user);
    }

    public function test_system_は資料だけを根拠にし_番号を示し_わからなければ決まり文句で答えるよう指示する(): void
    {
        $prompt = (new PromptBuilder(3))->build('質問', $this->ranking([]));

        $this->assertSame(PromptBuilder::SYSTEM, $prompt->system);
        $this->assertStringContainsString('資料」だけを根拠に', $prompt->system);
        $this->assertStringContainsString('[1]', $prompt->system);
        $this->assertStringContainsString('「'.PromptBuilder::NO_ANSWER.'」とだけ答えてください', $prompt->system);
        // 行頭の空白（ヒアドキュメントのインデント）が残っていない
        $this->assertStringStartsWith('あなたは', $prompt->system);
        $this->assertDoesNotMatchRegularExpression('/^[ \t]+/m', $prompt->system);
    }

    public function test_資料がないときは資料なしと書き_対応表は空(): void
    {
        $prompt = (new PromptBuilder(3))->build('質問', $this->ranking([]));

        $this->assertSame([], $prompt->sources);
        $this->assertStringContainsString('（該当する資料はありません）', $prompt->user);
    }

    public function test_full_text_は_system_と_user_をまとめる(): void
    {
        $prompt = new Prompt('S', 'U', []);

        $this->assertSame("[system]\nS\n\n[user]\nU", $prompt->fullText());
    }

    public function test_回答から資料番号を出てきた順に重複なしで取り出す(): void
    {
        $this->assertSame([2, 5, 1], Prompt::citedNumbers('C001 は PHP の経験がある [2][5]。C002 も [1]。再掲 [2]'));
        $this->assertSame([], Prompt::citedNumbers(PromptBuilder::NO_ANSWER));
        // [a] や [1.5] は番号として扱わない
        $this->assertSame([], Prompt::citedNumbers('[a] [1.5] [ 3 ]'));
    }

    public function test_候補者あたりのチャンク数が1未満なら例外(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromptBuilder(0);
    }
}
