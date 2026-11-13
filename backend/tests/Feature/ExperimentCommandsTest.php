<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\RandomChunkSeeder;
use App\Services\Rag\VectorIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 実験用のコマンド（要件定義書 第5章。RS-007）: rag:eval / rag:index / rag:explain / rag:seed-random。
 *
 * 埋め込み API は Http::fake() で偽装する（どの質問も軸 0 の単位ベクトル。チャンクの類似度は作るときに決める）。
 */
class ExperimentCommandsTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private string $evalFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();
        $this->evalFile = sys_get_temp_dir().'/rag-eval-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->evalFile);
        parent::tearDown();
    }

    /** 候補者 C001〜C004 を作る。類似度は C001 0.9 > C002 0.8 > C003 0.7。C004 はチャンクなし（どの質問でも圏外） */
    private function seedCandidates(): void
    {
        foreach (['C001' => 0.9, 'C002' => 0.8, 'C003' => 0.7, 'C004' => null] as $code => $similarity) {
            $candidate = Candidate::create(['code' => $code, 'name' => "架空 {$code}"]);
            if ($similarity !== null) {
                $this->chunk($this->document($candidate, Document::TYPE_CAREER_HISTORY), 0, $similarity, 'スキル', "{$code} の経験");
            }
        }
    }

    private function writeEval(array $data): void
    {
        file_put_contents($this->evalFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    // ===== rag:eval =====

    public function test_rag_eval_は_recall_at_k_と平均順位を表示し_claude_を呼ばない(): void
    {
        $this->seedCandidates();
        $this->writeEval(['k' => 2, 'questions' => [
            // C001 は1位、C003 は3位（k=2 の外）→ Recall 1/2
            ['question' => 'PHP の経験', 'expected' => ['C001', 'C003']],
            // C002 は2位、C004 は圏外 → Recall 1/2
            ['question' => 'AWS の経験', 'expected' => ['C002', 'C004']],
        ]]);

        $this->artisan('rag:eval', ['--file' => $this->evalFile])
            ->expectsOutputToContain('2問')
            ->expectsOutputToContain('k = 2')
            ->expectsOutputToContain('*C001:1 C003:3')
            ->expectsOutputToContain('*C002:2 C004:圏外')
            ->expectsOutputToContain('Recall@2（質問ごとの平均）: 0.500')
            ->expectsOutputToContain('正解者全体では 2/4 = 0.500')
            // 順位が付いたのは C001(1)・C003(3)・C002(2) → 平均 2.00
            ->expectsOutputToContain('平均順位: 2.00（順位が付いた正解者 3 名の平均）')
            ->expectsOutputToContain('圏外: 1 名')
            ->assertSuccessful();

        // 質問の埋め込みは1回の呼び出しにまとめる。Claude は呼ばない
        $this->assertCount(1, $this->embeddingRequests);
        $this->assertSame(['PHP の経験', 'AWS の経験'], $this->embeddingRequests[0]['input']);
        $this->assertCount(0, $this->claudeRequests);
    }

    public function test_rag_eval_の_k_はオプションで上書きでき_条件も変えられる(): void
    {
        $this->seedCandidates();
        $this->writeEval(['k' => 1, 'questions' => [['question' => 'PHP', 'expected' => ['C001', 'C003']]]]);

        $this->artisan('rag:eval', ['--file' => $this->evalFile, '--k' => '3', '--score' => 'avg3'])
            ->expectsOutputToContain('スコア=avg3')
            ->expectsOutputToContain('*C001:1 *C003:3')
            ->expectsOutputToContain('Recall@3（質問ごとの平均）: 1.000')
            ->assertSuccessful();

        // 下限を上げると C003（0.7）は1段目の集約から外れて圏外になる
        $this->artisan('rag:eval', ['--file' => $this->evalFile, '--k' => '3', '--min-similarity' => '0.75'])
            ->expectsOutputToContain('*C001:1 C003:圏外')
            ->expectsOutputToContain('Recall@3（質問ごとの平均）: 0.500')
            ->assertSuccessful();
    }

    public function test_rag_eval_はリポジトリの評価データを読める(): void
    {
        $this->seedCandidates();

        // ファイルを指定しなければ storage/app/eval.json（サンプルの10問）
        $this->artisan('rag:eval')
            ->expectsOutputToContain('eval.json（10問）')
            ->expectsOutputToContain('k = 5')
            ->assertSuccessful();
    }

    public function test_rag_eval_は評価データや引数が不正なら_api_を呼ばずに失敗する(): void
    {
        $this->artisan('rag:eval', ['--file' => '/nonexistent/eval.json'])
            ->expectsOutputToContain('評価データが見つからない')
            ->assertFailed();

        file_put_contents($this->evalFile, '{');
        $this->artisan('rag:eval', ['--file' => $this->evalFile])->expectsOutputToContain('JSON として読めない')->assertFailed();

        $this->writeEval(['questions' => [['question' => 'PHP', 'expected' => []]]]);
        $this->artisan('rag:eval', ['--file' => $this->evalFile])->expectsOutputToContain('1問目: expected')->assertFailed();

        $this->writeEval(['questions' => [['question' => 'PHP', 'expected' => ['C001']]]]);
        $this->artisan('rag:eval', ['--file' => $this->evalFile, '--k' => '0'])->expectsOutputToContain('k は1以上')->assertFailed();
        $this->artisan('rag:eval', ['--file' => $this->evalFile, '--score' => 'x'])->assertFailed();

        $this->assertCount(0, $this->embeddingRequests);
    }

    // ===== rag:index / rag:explain =====

    public function test_rag_index_は_hnsw_索引を作り_消し_状態を表示する(): void
    {
        $this->artisan('rag:index')->expectsOutputToContain('状態: 索引なし')->assertSuccessful();

        $this->artisan('rag:index', ['action' => 'create'])
            ->expectsOutputToContain('作った: '.VectorIndex::NAME)
            ->expectsOutputToContain('状態: 索引あり')
            ->expectsOutputToContain('USING hnsw (embedding vector_cosine_ops)')
            ->assertSuccessful();
        $this->assertTrue(app(VectorIndex::class)->exists());

        // 2回目は何もしない
        $this->artisan('rag:index', ['action' => 'create'])->expectsOutputToContain('索引はすでにある')->assertSuccessful();

        $this->artisan('rag:index', ['action' => 'drop'])
            ->expectsOutputToContain('消した: '.VectorIndex::NAME)
            ->expectsOutputToContain('状態: 索引なし')
            ->assertSuccessful();
        $this->assertFalse(app(VectorIndex::class)->exists());
        $this->artisan('rag:index', ['action' => 'drop'])->expectsOutputToContain('索引はない')->assertSuccessful();

        $this->artisan('rag:index', ['action' => 'rebuild'])->assertFailed();
        $this->artisan('rag:index', ['action' => 'create', '--m' => '0'])->assertFailed();
        $this->assertFalse(app(VectorIndex::class)->exists());
    }

    public function test_rag_index_の前後で_rag_explain_の実行計画が変わる(): void
    {
        // 数件だと planner は索引があっても全件読みを選ぶので、rag:seed-random で件数を増やす（約5000件）。
        // テスト時間を短くするため、索引は小さい m / ef_construction で作る（精度は下がるが、実行計画を見るには十分）
        $this->seedCandidates();
        $this->artisan('rag:seed-random', ['count' => '5000'])->assertSuccessful();

        // 索引なし: chunks を全件読んで距離で並べ替える（Seq Scan → Sort）。LIMIT どおり 50 件返る
        Artisan::call('rag:explain', ['question' => 'PHP の経験']);
        $before = Artisan::output();
        $this->assertStringContainsString('索引: なし', $before);
        $this->assertStringContainsString('EXPLAIN ANALYZE', $before);
        $this->assertStringContainsString('Seq Scan on chunks', $before);
        $this->assertStringNotContainsString('Index Scan using '.VectorIndex::NAME, $before);
        $this->assertStringContainsString('索引を使っていない', $before);
        $this->assertStringContainsString('返った行数: 50（LIMIT 50）', $before);
        $this->assertStringContainsString('実行時間:', $before);
        // ベクトルの値は次元数だけにして表示する
        $this->assertStringContainsString("'[…".config('rag.embedding.dimensions')."次元…]'::vector", $before);

        $this->artisan('rag:index', ['action' => 'create', '--m' => '4', '--ef-construction' => '16'])->assertSuccessful();

        // 索引あり: planner 自身が HNSW 索引を選ぶ。索引からは hnsw.ef_search（既定 40）件までしか取らない
        Artisan::call('rag:explain', ['question' => 'PHP の経験']);
        $after = Artisan::output();
        $this->assertStringContainsString('索引: あり', $after);
        $this->assertStringContainsString('Index Scan using '.VectorIndex::NAME, $after);
        $this->assertStringNotContainsString('Seq Scan on chunks', $after);
        $this->assertStringContainsString('HNSW 索引を使った', $after);
        $this->assertStringContainsString('返った行数: 40（LIMIT 50）', $after);
        $this->assertStringContainsString('LIMIT より少ない', $after);

        // ef_search を LIMIT 以上にすると 50 件返る。設定は SET LOCAL なので explain の後には残らない
        Artisan::call('rag:explain', ['question' => 'PHP の経験', '--set' => ['hnsw.ef_search=100']]);
        $tuned = Artisan::output();
        $this->assertStringContainsString('設定: hnsw.ef_search=100', $tuned);
        $this->assertStringContainsString('返った行数: 50（LIMIT 50）', $tuned);
        $this->assertNotSame('100', DB::selectOne("SELECT current_setting('hnsw.ef_search', true) AS v")->v);

        // 索引を消すと全件読みに戻る
        $this->artisan('rag:index', ['action' => 'drop'])->assertSuccessful();
        Artisan::call('rag:explain', ['question' => 'PHP の経験']);
        $this->assertStringContainsString('Seq Scan on chunks', Artisan::output());
    }

    public function test_索引があっても_planner_が全件読みを選んだときはその旨を出す(): void
    {
        // planner が索引を使うかは件数とテーブルの大きさの見積もりで決まる（前のテストがロールバックした行も
        // ページとして残るので、順番で変わる）。ここでは enable_indexscan=off で全件読みを選ばせ、説明文だけを確かめる
        $this->seedCandidates();
        $this->artisan('rag:index', ['action' => 'create'])->assertSuccessful();

        Artisan::call('rag:explain', ['--random' => true, '--set' => ['enable_indexscan=off']]);
        $output = Artisan::output();

        $this->assertStringContainsString('索引: あり', $output);
        $this->assertStringContainsString('Seq Scan on chunks', $output);
        $this->assertStringContainsString('索引はあるが planner が全件読みを選んだ', $output);
    }

    public function test_rag_explain_の_random_は埋め込み_api_を呼ばない(): void
    {
        $this->seedCandidates();

        Artisan::call('rag:explain', ['--random' => true, '--limit' => '2']);
        $output = Artisan::output();

        $this->assertStringContainsString('（ランダムなベクトル）', $output);
        $this->assertStringContainsString('索引: なし / LIMIT 2', $output);
        $this->assertStringContainsString('返った行数: 2（LIMIT 2）', $output);
        $this->assertCount(0, $this->embeddingRequests);
    }

    public function test_rag_explain_は引数が不正なら_api_を呼ばずに失敗する(): void
    {
        $this->artisan('rag:explain')->expectsOutputToContain('--random を付けること')->assertFailed();
        $this->artisan('rag:explain', ['question' => 'PHP', '--type' => 'x'])->assertFailed();
        $this->artisan('rag:explain', ['question' => 'PHP', '--set' => ['enable_seqscan']])->expectsOutputToContain('名前=値')->assertFailed();
        // 設定名は SQL に直接書くので、記号を含む名前は受け付けない
        $this->artisan('rag:explain', ['--random' => true, '--set' => ['x;drop table chunks=1']])->expectsOutputToContain('設定名が不正')->assertFailed();

        $this->assertCount(0, $this->embeddingRequests);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('chunks'));
    }

    // ===== rag:seed-random =====

    public function test_rag_seed_random_はランダムなベクトルのチャンクを目印つきで足し_purge_で消す(): void
    {
        $this->seedCandidates();
        $real = Chunk::count();

        $this->artisan('rag:seed-random', ['count' => '30'])
            ->expectsOutputToContain('ランダムなチャンクを 30 件入れた')
            ->assertSuccessful();
        // 2回目は続きの番号で足す
        $this->artisan('rag:seed-random', ['count' => '5'])->expectsOutputToContain('実験用は合計 35 件')->assertSuccessful();

        $candidate = Candidate::where('code', RandomChunkSeeder::RANDOM_CODE)->sole();
        $chunks = Chunk::whereRelation('document', 'candidate_id', $candidate->id)->orderBy('chunk_index')->get();
        $this->assertCount(35, $chunks);
        $this->assertSame(range(0, 34), $chunks->pluck('chunk_index')->all());
        $this->assertTrue($chunks->every(fn (Chunk $c) => $c->metadata['random'] === true && $c->embedding !== null));
        $this->assertSame(35, DB::table('chunks')->whereRaw("metadata->>'random' = 'true'")->count());

        // 行ごとに別のベクトルになっている（同じベクトルが並ぶと実験にならない）
        $this->assertSame(35, DB::table('chunks')->whereRaw("metadata->>'random' = 'true'")->distinct()->count(DB::raw('embedding::text')));
        // 次元数は設定どおり
        $this->assertSame((int) config('rag.embedding.dimensions'), (int) DB::selectOne("SELECT vector_dims(embedding) AS d FROM chunks WHERE metadata->>'random' = 'true' LIMIT 1")->d);

        // API は使わない
        $this->assertCount(0, $this->embeddingRequests);

        $this->artisan('rag:seed-random', ['--purge' => true])
            ->expectsOutputToContain('チャンク 35 件')
            ->assertSuccessful();
        $this->assertSame($real, Chunk::count());
        $this->assertFalse(Candidate::where('code', RandomChunkSeeder::RANDOM_CODE)->exists());
        $this->assertSame(4, Candidate::count());
    }

    public function test_rag_seed_random_は件数が不正なら何もしない(): void
    {
        $this->artisan('rag:seed-random')->expectsOutputToContain('1以上の整数')->assertFailed();
        $this->artisan('rag:seed-random', ['count' => '0'])->assertFailed();
        $this->artisan('rag:seed-random', ['count' => '1.5'])->assertFailed();
        $this->artisan('rag:seed-random', ['count' => (string) (RandomChunkSeeder::MAX_COUNT + 1)])
            ->expectsOutputToContain('件数は1〜')
            ->assertFailed();

        $this->assertSame(0, Chunk::count());
        $this->assertFalse(Candidate::where('code', RandomChunkSeeder::RANDOM_CODE)->exists());
    }
}
