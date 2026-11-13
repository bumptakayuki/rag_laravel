<?php

namespace Tests\Unit;

use App\Services\Rag\Evaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * rag:eval の採点（Evaluator の純粋な関数。DB・API を使わない）。
 */
class EvaluatorTest extends TestCase
{
    public function test_正解者の順位と上位_k_名に入った数を数える(): void
    {
        $result = Evaluator::scoreQuestion(['C001', 'C005', 'C009'], ['C005', 'C002', 'C001', 'C003'], 2);

        $this->assertSame(['C001' => 3, 'C005' => 1, 'C009' => null], $result['ranks']);
        $this->assertSame(1, $result['hits']);
        $this->assertEqualsWithDelta(1 / 3, $result['recall'], 1e-9);
    }

    /** @return array<string, array{0: list<string>, 1: list<string>, 2: int, 3: float}> */
    public static function boundaries(): array
    {
        return [
            'ちょうど k 位は当たり' => [['C003'], ['C001', 'C002', 'C003'], 3, 1.0],
            'k+1 位は外れ' => [['C003'], ['C001', 'C002', 'C003'], 2, 0.0],
            '結果が空なら0' => [['C001'], [], 5, 0.0],
            '全員当たり' => [['C001', 'C002'], ['C002', 'C001'], 2, 1.0],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_境界(array $expected, array $ranked, int $k, float $recall): void
    {
        $this->assertSame($recall, Evaluator::scoreQuestion($expected, $ranked, $k)['recall']);
    }

    public function test_全体は質問ごとの_recall_の平均で_平均順位は圏外を除く(): void
    {
        $summary = Evaluator::summarize([
            ['ranks' => ['C001' => 1], 'hits' => 1, 'recall' => 1.0],
            ['ranks' => ['C002' => 4, 'C003' => null, 'C004' => 2, 'C005' => 9], 'hits' => 1, 'recall' => 0.25],
        ], 3);

        // 質問ごとの平均 (1.0 + 0.25) / 2。正解者全体で数えると 2/5 になる（重みが違う）
        $this->assertSame(0.625, $summary['recall']);
        $this->assertSame(2, $summary['hits']);
        $this->assertSame(5, $summary['expected']);
        $this->assertSame((1 + 4 + 2 + 9) / 4, $summary['mean_rank']);
        $this->assertSame(4, $summary['ranked']);
        $this->assertSame(1, $summary['not_found']);
    }

    public function test_全員圏外なら平均順位は_null(): void
    {
        $summary = Evaluator::summarize([['ranks' => ['C001' => null], 'hits' => 0, 'recall' => 0.0]], 5);

        $this->assertNull($summary['mean_rank']);
        $this->assertSame(1, $summary['not_found']);
        $this->assertSame(0.0, $summary['recall']);
    }

    public function test_load_は正解の重複を除き_k_と注記を読む(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eval');
        file_put_contents($path, json_encode(['k' => 3, 'questions' => [
            ['question' => 'PHP', 'expected' => ['C001', 'C001', 'C002'], 'note' => 'メモ'],
            ['question' => 'AWS', 'expected' => ['C005']],
        ]]));

        try {
            $data = Evaluator::load($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(3, $data['k']);
        $this->assertSame(['C001', 'C002'], $data['questions'][0]['expected']);
        $this->assertSame('メモ', $data['questions'][0]['note']);
        $this->assertNull($data['questions'][1]['note']);
    }
}
