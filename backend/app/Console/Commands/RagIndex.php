<?php

namespace App\Console\Commands;

use App\Services\Rag\VectorIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ベクトル索引（HNSW）を作る・消す・状態を見る（要件定義書 第5章）。
 *
 * 作る前後で rag:explain を実行すると、実行計画が Seq Scan（全件の距離を計算して並べ替え）から
 * Index Scan（索引をたどる）に変わるのを確かめられる。チャンクが数百件しかないと、索引があっても
 * planner は全件を見るほうが安いと判断して Seq Scan のままのことがある（rag:seed-random で件数を増やす）。
 */
class RagIndex extends Command
{
    protected $signature = 'rag:index
        {action=status : create（作る）/ drop（消す）/ status（状態を見る）}
        {--m=16 : HNSW の m（各点からつなぐ数）。create のときだけ}
        {--ef-construction=64 : HNSW の ef_construction（作るときに見る候補の数）。create のときだけ}';

    protected $description = 'chunks.embedding の HNSW 索引（vector_cosine_ops）を作る・消す・状態を見る';

    public function handle(VectorIndex $index): int
    {
        $action = (string) $this->argument('action');

        switch ($action) {
            case 'create':
                $m = $this->positiveInt('m');
                $ef = $this->positiveInt('ef-construction');
                if ($m === null || $ef === null) {
                    return self::FAILURE;
                }
                $chunks = DB::table('chunks')->whereNotNull('embedding')->count();
                $this->line("索引を作っています（チャンク {$chunks} 件、m = {$m}、ef_construction = {$ef}）…");
                $started = hrtime(true);
                if (! $index->create($m, $ef)) {
                    $this->warn('索引はすでにある（作り直すときは drop してから create）');
                    break;
                }
                $this->info(sprintf('作った: %s（%.1f 秒）', VectorIndex::NAME, (hrtime(true) - $started) / 1e9));
                break;

            case 'drop':
                $index->drop()
                    ? $this->info('消した: '.VectorIndex::NAME)
                    : $this->warn('索引はない');
                break;

            case 'status':
                break;

            default:
                $this->error("action は create / drop / status のどれか: {$action}");

                return self::FAILURE;
        }

        $this->renderStatus($index);

        return self::SUCCESS;
    }

    private function renderStatus(VectorIndex $index): void
    {
        $definition = $index->definition();
        if ($definition === null) {
            $this->line('状態: 索引なし（検索は全チャンクとの距離を計算して並べ替える）');

            return;
        }
        $this->line("状態: 索引あり（{$index->size()}）");
        $this->line("  {$definition}");
        // ef_search（検索のときに見る候補の数）は既定 40。LIMIT がこれより大きいと、索引からは最大 ef_search 件しか返らない
        // pgvector のライブラリがまだ読み込まれていない接続では設定名が未登録なので、current_setting(…, true) で NULL を受ける
        $efSearch = DB::selectOne("SELECT current_setting('hnsw.ef_search', true) AS v")->v ?? '40（pgvector の既定）';
        $this->line("  検索時の hnsw.ef_search: {$efSearch}"
            .'（索引から返る件数の上限。RAG_CHUNK_LIMIT がこれより大きいと取りこぼす。rag:explain --set=hnsw.ef_search=100 で試せる）');
    }

    private function positiveInt(string $name): ?int
    {
        $value = (string) $this->option($name);
        if (! preg_match('/\A[1-9]\d*\z/', $value)) {
            $this->error("--{$name} には1以上の整数を指定すること: {$value}");

            return null;
        }

        return (int) $value;
    }
}
