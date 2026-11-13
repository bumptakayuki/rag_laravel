<?php

namespace App\Services\Rag;

use App\Models\Candidate;
use App\Services\Rag\Contracts\Embedder;
use InvalidArgumentException;
use JsonException;

/**
 * 検索の評価（rag:eval。要件定義書 第5章）。Claude は呼ばない。
 *
 * eval.json の各質問で検索し（回答は作らない）、正解の候補者が何位に来たかを調べる。
 *
 *   Recall@k  = 上位 k 名に入った正解者の数 ÷ 正解者の数（質問ごと）。全体は質問ごとの値の平均
 *   平均順位  = 正解者の順位の平均。1段目（chunk_limit 件のチャンク）に1件もヒットしなかった正解者は
 *               順位が付かない（圏外）ので平均に入れず、数を別に出す
 *
 * 上位 k 名より下の順位も知りたいので、集約では top_k を区切らずに全員を並べる。
 * 質問の埋め込みは1回の API 呼び出しでまとめて行う（Embedder がバッチに分ける）。
 */
class Evaluator
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly Retriever $retriever,
    ) {}

    /**
     * eval.json を読む。形が違えば例外
     *
     * @return array{k: int|null, questions: list<array{question: string, expected: list<string>, note: string|null}>}
     */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("評価データが見つからない: {$path}");
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("評価データが JSON として読めない: {$path}（{$e->getMessage()}）");
        }
        if (! is_array($data) || ! is_array($data['questions'] ?? null) || $data['questions'] === []) {
            throw new InvalidArgumentException('評価データには questions（1問以上）が必要');
        }

        $questions = [];
        foreach ($data['questions'] as $i => $q) {
            $number = $i + 1;
            if (! is_array($q) || ! is_string($q['question'] ?? null) || trim($q['question']) === '') {
                throw new InvalidArgumentException("{$number}問目: question（質問文）がない");
            }
            $expected = $q['expected'] ?? null;
            if (! is_array($expected) || $expected === [] || array_filter($expected, fn ($c) => ! is_string($c) || $c === '') !== []) {
                throw new InvalidArgumentException("{$number}問目: expected（正解の候補者コードの配列）がない");
            }
            $questions[] = [
                'question' => $q['question'],
                'expected' => array_values(array_unique($expected)),
                'note' => is_string($q['note'] ?? null) ? $q['note'] : null,
            ];
        }

        $k = $data['k'] ?? null;

        return ['k' => is_int($k) ? $k : null, 'questions' => $questions];
    }

    /**
     * 全問を検索して評価する。
     *
     * @param  list<array{question: string, expected: list<string>, note?: string|null}>  $questions
     * @return array{questions: list<array{question: string, expected: list<string>, note: string|null, ranks: array<string, int|null>, hits: int, recall: float}>, summary: array{k: int, recall: float, hits: int, expected: int, mean_rank: float|null, ranked: int, not_found: int}}
     */
    public function run(array $questions, SearchOptions $options, int $k): array
    {
        if ($k < 1) {
            throw new InvalidArgumentException("k は1以上にすること: {$k}");
        }
        // 設定の誤りは API を呼ぶ前に止める。全員を並べるため top_k は区切らない
        $ranker = $options->withTopK(PHP_INT_MAX)->candidateRanker();
        $this->retriever->validate(Retriever::SCOPE_CANDIDATE, $options->chunkLimit, $options->documentType);

        $vectors = $this->embedder->embed(array_column($questions, 'question'));
        $codes = Candidate::pluck('code', 'id')->all();

        $results = [];
        foreach ($questions as $i => $q) {
            $chunks = $this->retriever->retrieveByVector($vectors[$i], Retriever::SCOPE_CANDIDATE, $options->chunkLimit, $options->documentType);
            $ranked = array_map(fn (RankedGroup $g) => $codes[$g->key] ?? "id={$g->key}", $ranker->rank($chunks)->groups);

            $results[] = ['question' => $q['question'], 'expected' => $q['expected'], 'note' => $q['note'] ?? null]
                + self::scoreQuestion($q['expected'], $ranked, $k);
        }

        return ['questions' => $results, 'summary' => self::summarize($results, $k)];
    }

    /**
     * 1問分の採点（純粋な関数）。
     *
     * @param  list<string>  $expected  正解の候補者コード
     * @param  list<string>  $ranked  検索結果の候補者コード（1位から順に）
     * @return array{ranks: array<string, int|null>, hits: int, recall: float} ranks は正解者ごとの順位（圏外は null）
     */
    public static function scoreQuestion(array $expected, array $ranked, int $k): array
    {
        $positions = array_flip($ranked);
        $ranks = [];
        foreach ($expected as $code) {
            $ranks[$code] = isset($positions[$code]) ? $positions[$code] + 1 : null;
        }
        $hits = count(array_filter($ranks, fn (?int $rank) => $rank !== null && $rank <= $k));

        return ['ranks' => $ranks, 'hits' => $hits, 'recall' => $expected === [] ? 0.0 : (float) $hits / count($expected)];
    }

    /**
     * 全問の集計（純粋な関数）。
     *
     * @param  list<array{ranks: array<string, int|null>, hits: int, recall: float}>  $results
     * @return array{k: int, recall: float, hits: int, expected: int, mean_rank: float|null, ranked: int, not_found: int}
     */
    public static function summarize(array $results, int $k): array
    {
        $ranks = array_merge(...array_map(fn (array $r) => array_values($r['ranks']), $results ?: [['ranks' => []]]));
        $found = array_values(array_filter($ranks, fn (?int $rank) => $rank !== null));

        return [
            'k' => $k,
            // 質問ごとの Recall@k の平均（正解者の多い質問に引っ張られないように、1問を同じ重さで数える）
            'recall' => $results === [] ? 0.0 : array_sum(array_column($results, 'recall')) / count($results),
            // 参考: 正解者全体で数えた値（上位 k 名に入った正解者の延べ数 / 正解者の延べ数）
            'hits' => array_sum(array_column($results, 'hits')),
            'expected' => count($ranks),
            'mean_rank' => $found === [] ? null : array_sum($found) / count($found),
            'ranked' => count($found),
            'not_found' => count($ranks) - count($found),
        ];
    }
}
