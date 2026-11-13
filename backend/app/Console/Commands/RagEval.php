<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersSearchResults;
use App\Services\Rag\Evaluator;
use App\Services\Rag\SearchOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 検索の評価（要件定義書 第5章）。eval.json の質問で検索し、Recall@k と平均順位を表示する。
 *
 * Claude は呼ばない。質問の埋め込みに OpenAI Embeddings API を1回（10問まとめて）呼ぶ。
 * --score / --doc-type / --limit / --min-similarity を変えて実行すると、設定ごとの検索の精度を比べられる。
 */
class RagEval extends Command
{
    use RendersSearchResults;

    protected $signature = 'rag:eval
        {--file= : 評価データ（JSON）。省略時は storage/app/eval.json}
        {--k= : 上位何名までを「当たり」とするか。省略時は評価データの k（なければ RAG_TOP_K）}
        {--doc-type= : 候補者の文書を種別で絞る（rirekisho: 履歴書 / shokumu: 職務経歴書）}
        {--score= : スコアの集約方法（max / avg3 / max_plus_hits）。省略時は RAG_CANDIDATE_SCORE}
        {--limit= : 1段目で取得するチャンク数。省略時は RAG_CHUNK_LIMIT}
        {--min-similarity= : 類似度の下限。省略時は RAG_MIN_SIMILARITY}';

    protected $description = '評価データの質問で検索し、正解の候補者が上位 k 名に入った割合（Recall@k）と平均順位を表示する（Claude は呼ばない）';

    public function handle(Evaluator $evaluator): int
    {
        try {
            $path = (string) ($this->option('file') ?? storage_path('app/eval.json'));
            $data = Evaluator::load($path);
            $options = SearchOptions::fromConfig([
                'chunk_limit' => $this->intOption('limit'),
                'min_similarity' => $this->floatOption('min-similarity'),
                'score' => $this->option('score'),
                'document_type' => $this->option('doc-type'),
            ]);
            $k = $this->intOption('k') ?? $data['k'] ?? $options->topK;

            $report = $evaluator->run($data['questions'], $options, $k);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("評価データ: {$path}（".count($data['questions']).'問）');
        $this->renderConditions('候補者', $options);
        $this->line("k = {$k}（上位 {$k} 名に入れば当たり）");
        $this->newLine();

        $rows = [];
        foreach ($report['questions'] as $i => $q) {
            $rows[] = [
                $i + 1,
                Str::limit($q['question'], 36),
                // 正解者ごとの順位。上位 k 名に入った人には * を付ける
                collect($q['ranks'])->map(fn (?int $rank, string $code) => $rank === null
                    ? "{$code}:圏外"
                    : ($rank <= $k ? "*{$code}:{$rank}" : "{$code}:{$rank}"))->implode(' '),
                sprintf('%d/%d = %.2f', $q['hits'], count($q['expected']), $q['recall']),
            ];
        }
        $this->table(['#', '質問', '正解者:順位（* = 上位 k 名）', "Recall@{$k}"], $rows);

        $s = $report['summary'];
        $this->info(sprintf('Recall@%d（質問ごとの平均）: %.3f', $k, $s['recall']));
        $this->line(sprintf('  参考: 正解者全体では %d/%d = %.3f', $s['hits'], $s['expected'], $s['expected'] === 0 ? 0 : $s['hits'] / $s['expected']));
        $this->info($s['mean_rank'] === null
            ? '平均順位: なし（正解者が1人も1段目に入らなかった）'
            : sprintf('平均順位: %.2f（順位が付いた正解者 %d 名の平均）', $s['mean_rank'], $s['ranked']));
        if ($s['not_found'] > 0) {
            $this->warn(sprintf('圏外: %d 名（1段目の %d チャンクに1件もヒットしなかった。平均順位には入れていない）', $s['not_found'], $options->chunkLimit));
        }

        return self::SUCCESS;
    }
}
