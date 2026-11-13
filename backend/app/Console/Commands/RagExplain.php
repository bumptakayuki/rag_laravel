<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersSearchResults;
use App\Services\Rag\Contracts\Embedder;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use App\Services\Rag\VectorIndex;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * 検索 SQL の実行計画を見る（要件定義書 第5章）。Retriever が実行するのと同じ SQL を EXPLAIN ANALYZE する。
 *
 * 見どころ:
 * - 索引なし: Seq Scan on chunks → Sort（全チャンクとの距離を計算してから並べ替える）
 * - 索引あり: Index Scan using chunks_embedding_hnsw_idx（索引をたどって近いものから取る）
 * - 索引ありのとき、返った行数が LIMIT より少ないことがある（hnsw.ef_search の既定 40 件までしか索引から取らないため）
 *
 * 質問の埋め込みに OpenAI Embeddings API を1回呼ぶ。--random を付けると API を使わずランダムなベクトルで試す。
 */
class RagExplain extends Command
{
    use RendersSearchResults;

    protected $signature = 'rag:explain
        {question? : 質問文（--random のときは不要）}
        {--random : 質問の代わりにランダムなベクトルを使う（API を呼ばない）}
        {--type=candidate : 検索対象（candidate: 候補者 / job: 求人票）}
        {--doc-type= : 候補者の文書を種別で絞る（rirekisho / shokumu）}
        {--limit= : 1段目で取得するチャンク数（LIMIT）。省略時は RAG_CHUNK_LIMIT}
        {--set=* : この1回だけ変える planner の設定（例: --set=enable_seqscan=off --set=hnsw.ef_search=100）}';

    protected $description = '検索 SQL を EXPLAIN ANALYZE して実行計画を表示する（索引が使われたかを確かめる）';

    public function handle(Retriever $retriever, Embedder $embedder, VectorIndex $index): int
    {
        try {
            $type = (string) $this->option('type');
            $scope = match ($type) {
                'candidate' => Retriever::SCOPE_CANDIDATE,
                'job' => Retriever::SCOPE_JOB,
                default => throw new InvalidArgumentException("--type は candidate か job: {$type}"),
            };
            $options = SearchOptions::fromConfig([
                'chunk_limit' => $this->intOption('limit'),
                'document_type' => $this->option('doc-type'),
            ]);
            $settings = $this->settings();
            $retriever->validate($scope, $options->chunkLimit, $options->documentType);

            $question = $this->argument('question');
            if ($this->option('random')) {
                $vector = self::randomVector((int) config('rag.embedding.dimensions'));
                $label = '（ランダムなベクトル）';
            } else {
                if (! is_string($question) || trim($question) === '') {
                    throw new InvalidArgumentException('質問を指定するか、--random を付けること');
                }
                $vector = $embedder->embed([$question])[0];
                $label = $question;
            }

            $plan = $retriever->explain($vector, $scope, $options->chunkLimit, $options->documentType, $settings);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("質問: {$label}");
        $this->line(sprintf(
            '索引: %s / LIMIT %d%s',
            $index->exists() ? 'あり（'.VectorIndex::NAME.'）' : 'なし',
            $options->chunkLimit,
            $settings === [] ? '' : ' / 設定: '.collect($settings)->map(fn ($v, $k) => "{$k}={$v}")->implode(', '),
        ));
        $this->newLine();
        $this->line($retriever->sqlForDisplay($scope, $options->chunkLimit, $options->documentType));
        $this->newLine();
        $this->info('--- EXPLAIN ANALYZE ---');
        foreach ($plan as $line) {
            // 質問ベクトル（1536 個の数）がそのまま出ると読めないので、次元数だけにする
            $this->line(preg_replace_callback(
                "/'\\[([^\\]]*)\\]'::vector/",
                fn (array $m) => "'[…".(substr_count($m[1], ',') + 1)."次元…]'::vector",
                $line,
            ));
        }
        $this->newLine();
        $this->renderVerdict($plan, $options->chunkLimit);

        return self::SUCCESS;
    }

    /**
     * 実行計画の読みどころを短くまとめる
     *
     * @param  list<string>  $plan
     */
    private function renderVerdict(array $plan, int $limit): void
    {
        $text = implode("\n", $plan);
        $usesIndex = str_contains($text, 'Index Scan using '.VectorIndex::NAME);
        $this->info($usesIndex
            ? '→ HNSW 索引を使った（Index Scan。近似検索）'
            : '→ 索引を使っていない（chunks を全件読み、距離で並べ替えた。正確な検索）');
        if (! $usesIndex && app(VectorIndex::class)->exists()) {
            // 索引があっても、planner が全件読みのほうが安いと見積もれば使わない
            $this->line('  索引はあるが planner が全件読みを選んだ。件数が少ないとき、LIMIT が hnsw.ef_search（既定 40）より大きいときに起きる（--set=hnsw.ef_search=60 などで比べられる）');
        }

        // 一番上の Limit ノードの実際の行数 = 返った行数
        if (preg_match('/^Limit .*?actual time=[\d.]+\.\.[\d.]+ rows=([\d.]+)/m', $text, $m) === 1) {
            $rows = (int) $m[1];
            $this->line("  返った行数: {$rows}（LIMIT {$limit}）");
            if ($usesIndex && $rows < $limit) {
                $this->warn('  LIMIT より少ない。索引からは hnsw.ef_search（既定 40）件までしか取らず、そこから WHERE で絞るため（--set=hnsw.ef_search=100 などで比べられる）');
            }
        }
        if (preg_match('/^Execution Time: ([\d.]+) ms/m', $text, $m) === 1) {
            $this->line("  実行時間: {$m[1]} ms");
        }
    }

    /** --set=名前=値 を [名前 => 値] にする @return array<string, string> */
    private function settings(): array
    {
        $settings = [];
        foreach ((array) $this->option('set') as $item) {
            if (! preg_match('/\A([^=]+)=(.*)\z/', (string) $item, $m)) {
                throw new InvalidArgumentException("--set は 名前=値 の形で指定すること: {$item}");
            }
            $settings[trim($m[1])] = trim($m[2]);
        }

        return $settings;
    }

    /**
     * 正規分布の乱数を並べたベクトル（向きが一様にばらつく）。API を使わずに実行計画を見るためのもの
     *
     * @return list<float>
     */
    private static function randomVector(int $dimensions): array
    {
        $vector = [];
        for ($i = 0; $i < $dimensions; $i++) {
            // Box-Muller 法: 一様乱数2つから正規分布の乱数を1つ作る
            $u = (mt_rand() + 1) / (mt_getrandmax() + 2);
            $v = mt_rand() / mt_getrandmax();
            $vector[] = sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
        }

        return $vector;
    }
}
