<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\JobMatcher;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 検索（Retriever・rag:search・JobMatcher・rag:match）のテスト。
 *
 * ベクトルは「類似度がちょうど狙った値になる」ように作る:
 *   質問ベクトル q = 軸 A の単位ベクトル
 *   チャンク v = cosθ·A + sinθ·B（A・B は直交する軸）→ q との類似度は cosθ
 * 埋め込み API は Http::fake() で偽装し、送られたテキストに応じて q を返す（ネットワークは使わない）。
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    /** 軸の番号。PHP の質問 = 0、営業の質問 = 1、チャンク側の残りの成分 = 2 */
    private const AXIS_PHP = 0;

    private const AXIS_SALES = 1;

    private const AXIS_REST = 2;

    /** @var list<string> 埋め込み API に送られたテキスト */
    private array $embeddedTexts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dimensions = (int) config('rag.embedding.dimensions');
        Http::fake(['api.openai.com/*' => function (Request $request) use ($dimensions) {
            $data = [];
            foreach ($request['input'] as $i => $text) {
                $this->embeddedTexts[] = $text;
                $axis = str_contains($text, '営業') ? self::AXIS_SALES : self::AXIS_PHP;
                $data[] = ['index' => $i, 'embedding' => $this->axis($axis, $dimensions)];
            }

            return Http::response(['data' => $data]);
        }]);
    }

    /** @return list<float> */
    private function axis(int $axis, ?int $dimensions = null): array
    {
        $vector = array_fill(0, $dimensions ?? (int) config('rag.embedding.dimensions'), 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }

    /** 軸 $axis の質問との類似度が $similarity になるベクトル */
    private function vectorWithSimilarity(float $similarity, int $axis = self::AXIS_PHP): array
    {
        $vector = $this->axis($axis);
        $vector[$axis] = $similarity;
        $vector[self::AXIS_REST] = sqrt(1 - $similarity ** 2);

        return $vector;
    }

    private function document(?Candidate $candidate, string $type, string $content = '本文', ?string $title = null): Document
    {
        return Document::create([
            'candidate_id' => $candidate?->id,
            'type' => $type,
            'title' => $title ?? $type,
            'source_path' => uniqid('test/').'.md',
            'content' => $content,
            'content_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function chunk(Document $document, int $index, ?array $embedding, string $section = 'スキル', string $content = 'チャンク本文'): Chunk
    {
        return Chunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'content' => $content,
            'token_estimate' => mb_strlen($content),
            'metadata' => ['type' => $document->type, 'section' => $section],
            'embedding' => $embedding,
        ]);
    }

    /**
     * 候補者3名と求人票1件。PHP の質問に対して
     *   C001（PHP 経験者）: 職務経歴書 0.82 / 0.61、履歴書 0.55
     *   C002（PHP 経験者）: 職務経歴書 0.74
     *   C003（営業）      : 職務経歴書 0.12（営業の質問には 0.9）
     *   求人票（PHP）     : 0.95、求人票（営業）: 0.40 / 0.30（どちらも候補者検索には出てはいけない）
     *
     * @return array{c1: Candidate, c2: Candidate, c3: Candidate, job: Document}
     */
    private function seedSearchData(): array
    {
        $c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);
        $c3 = Candidate::create(['code' => 'C003', 'name' => '架空 三子']);

        $c1Shokumu = $this->document($c1, Document::TYPE_CAREER_HISTORY);
        $this->chunk($c1Shokumu, 0, $this->vectorWithSimilarity(0.82), 'スキル', 'PHP と Laravel で8年開発した');
        $this->chunk($c1Shokumu, 1, $this->vectorWithSimilarity(0.61), '職務経歴', 'API 設計を担当');
        $this->chunk($c1Shokumu, 2, null); // 埋め込み前のチャンクは検索に出ない
        $this->chunk($this->document($c1, Document::TYPE_RESUME), 0, $this->vectorWithSimilarity(0.55), '学歴', '情報工学科を卒業');

        $this->chunk($this->document($c2, Document::TYPE_CAREER_HISTORY), 0, $this->vectorWithSimilarity(0.74), 'スキル', 'PHP のフレームワークで EC サイトを開発');

        $c3Chunk = $this->vectorWithSimilarity(0.12);
        $c3Chunk[self::AXIS_SALES] = 0.9;
        $c3Chunk[self::AXIS_REST] = sqrt(1 - 0.12 ** 2 - 0.9 ** 2);
        $this->chunk($this->document($c3, Document::TYPE_CAREER_HISTORY), 0, $c3Chunk, 'スキル', '法人営業を5年');

        $job = $this->document(null, Document::TYPE_JOB, $this->jobMarkdown(), 'バックエンドエンジニア（PHP / Laravel）');
        $this->chunk($job, 0, $this->vectorWithSimilarity(0.95), '必須条件', 'PHP と Laravel の実務経験3年以上');

        $sales = $this->document(null, Document::TYPE_JOB, "# 求人票：法人営業\n\n## 必須条件\n\n- 法人営業の経験", '法人営業');
        $this->chunk($sales, 0, $this->vectorWithSimilarity(0.40), '必須条件', '法人営業の経験');
        $this->chunk($sales, 1, $this->vectorWithSimilarity(0.30), '業務内容', '既存顧客への提案');

        return ['c1' => $c1, 'c2' => $c2, 'c3' => $c3, 'job' => $job];
    }

    private function jobMarkdown(): string
    {
        return <<<'MD'
            # 求人票：バックエンドエンジニア（PHP / Laravel）

            会社名：株式会社サンプル

            ## 業務内容

            自社 SaaS の API 開発

            ## 必須条件

            - PHP と Laravel の実務経験3年以上

            ## 歓迎条件

            - 自動テストの経験

            ## 開発環境・働き方

            リモート併用
            MD;
    }

    // ---------------------------------------------------------------- Retriever

    public function test_retriever_は候補者の文書のチャンクを類似度の高い順に返す(): void
    {
        $this->seedSearchData();

        $chunks = app(Retriever::class)->retrieveByVector($this->axis(self::AXIS_PHP), Retriever::SCOPE_CANDIDATE, 50);

        // 求人票（0.95）と埋め込み前のチャンクは出ない
        $this->assertSame([0.82, 0.74, 0.61, 0.55, 0.12], array_map(fn ($c) => round($c->similarity, 4), $chunks));
        $this->assertSame([Document::TYPE_CAREER_HISTORY, 'スキル', 0], [$chunks[0]->documentType, $chunks[0]->section, $chunks[0]->chunkIndex]);
        $this->assertSame('PHP と Laravel で8年開発した', $chunks[0]->content);
        $this->assertNotContains(null, array_map(fn ($c) => $c->candidateId, $chunks));
    }

    public function test_retriever_は_limit_件までしか返さない(): void
    {
        $this->seedSearchData();

        $chunks = app(Retriever::class)->retrieveByVector($this->axis(self::AXIS_PHP), Retriever::SCOPE_CANDIDATE, 2);

        $this->assertSame([0.82, 0.74], array_map(fn ($c) => round($c->similarity, 4), $chunks));
    }

    public function test_retriever_は文書種別で絞れる(): void
    {
        $this->seedSearchData();

        $chunks = app(Retriever::class)->retrieveByVector($this->axis(self::AXIS_PHP), Retriever::SCOPE_CANDIDATE, 50, Document::TYPE_RESUME);

        $this->assertCount(1, $chunks);
        $this->assertSame(Document::TYPE_RESUME, $chunks[0]->documentType);
    }

    public function test_retriever_は求人票だけを検索できる(): void
    {
        ['job' => $job] = $this->seedSearchData();

        $chunks = app(Retriever::class)->retrieveByVector($this->axis(self::AXIS_PHP), Retriever::SCOPE_JOB, 50);

        // 求人票2件のチャンクだけが、類似度の高い順に出る（候補者のチャンクは出ない）
        $this->assertSame([0.95, 0.40, 0.30], array_map(fn ($c) => round($c->similarity, 4), $chunks));
        $this->assertSame($job->id, $chunks[0]->documentId);
        $this->assertSame([Document::TYPE_JOB], array_values(array_unique(array_map(fn ($c) => $c->documentType, $chunks))));
        $this->assertSame([null], array_values(array_unique(array_map(fn ($c) => $c->candidateId, $chunks))));
    }

    public function test_retriever_は質問を埋め込み_ap_i_でベクトル化してから検索する(): void
    {
        $this->seedSearchData();

        $chunks = app(Retriever::class)->retrieve('営業の経験がある人', Retriever::SCOPE_CANDIDATE, 1);

        $this->assertSame(['営業の経験がある人'], $this->embeddedTexts);
        $this->assertSame('法人営業を5年', $chunks[0]->content);
    }

    public function test_retriever_は引数が不正なら_ap_i_を呼ばずに例外にする(): void
    {
        $retriever = app(Retriever::class);
        foreach ([
            fn () => $retriever->retrieve('q', 'unknown', 10),
            fn () => $retriever->retrieve('q', Retriever::SCOPE_CANDIDATE, 0),
            fn () => $retriever->retrieve('q', Retriever::SCOPE_CANDIDATE, 10, Document::TYPE_JOB),
            fn () => $retriever->retrieve('q', Retriever::SCOPE_JOB, 10, Document::TYPE_RESUME),
        ] as $call) {
            try {
                $call();
                $this->fail('例外にならなかった');
            } catch (InvalidArgumentException) {
                // 期待どおり
            }
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- rag:search

    public function test_rag_search_は_ph_p_経験者を上位に並べ_ヒットチャンクを種別と類似度つきで出す(): void
    {
        $this->seedSearchData();

        $this->artisan('rag:search', ['question' => 'PHPの経験がある人'])
            ->expectsOutput('質問: PHPの経験がある人')
            ->expectsOutputToContain('取得 5 チャンク → 下限で除外後 5 チャンク → 3 名中 上位 3 名を表示')
            ->expectsOutput('1. C001 架空 一郎  スコア 0.8200  ヒット 3（履歴書 1 / 職務経歴書 2）')
            ->expectsOutput('   0.8200  職務経歴書・スキル #0  PHP と Laravel で8年開発した')
            ->expectsOutput('   0.6100  職務経歴書・職務経歴 #1  API 設計を担当')
            ->expectsOutput('   0.5500  履歴書・学歴 #0  情報工学科を卒業')
            ->expectsOutput('2. C002 架空 二郎  スコア 0.7400  ヒット 1（職務経歴書 1）')
            ->expectsOutput('3. C003 架空 三子  スコア 0.1200  ヒット 1（職務経歴書 1）')
            ->assertSuccessful();
    }

    public function test_rag_search_はオプションで下限_top_k_集約方法を変えられる(): void
    {
        $this->seedSearchData();

        $this->artisan('rag:search', ['question' => 'PHPの経験がある人', '--min-similarity' => '0.6', '--top-k' => '1', '--score' => 'avg3'])
            ->expectsOutputToContain('下限=0.6 / スコア=avg3 / top_k=1')
            ->expectsOutputToContain('取得 5 チャンク → 下限で除外後 3 チャンク → 2 名中 上位 1 名を表示')
            // avg3: C001 = (0.82 + 0.61) / 2 = 0.715 < C002 = 0.74
            ->expectsOutput('1. C002 架空 二郎  スコア 0.7400  ヒット 1（職務経歴書 1）')
            ->doesntExpectOutputToContain('C001')
            ->assertSuccessful();
    }

    public function test_rag_search_は文書種別で絞れる(): void
    {
        $this->seedSearchData();

        $this->artisan('rag:search', ['question' => 'PHPの経験がある人', '--doc-type' => 'rirekisho'])
            ->expectsOutputToContain('文書=履歴書')
            ->expectsOutput('1. C001 架空 一郎  スコア 0.5500  ヒット 1（履歴書 1）')
            ->doesntExpectOutputToContain('C002')
            ->assertSuccessful();
    }

    public function test_rag_search_type_job_は求人票を並べる(): void
    {
        ['job' => $job] = $this->seedSearchData();

        $this->artisan('rag:search', ['question' => 'PHPの経験がある人', '--type' => 'job'])
            ->expectsOutputToContain('対象=求人票')
            ->expectsOutput("1. [文書 {$job->id}] バックエンドエンジニア（PHP / Laravel）  スコア 0.9500  ヒット 1")
            ->expectsOutput('   0.9500  求人票・必須条件 #0  PHP と Laravel の実務経験3年以上')
            ->expectsOutputToContain('2. [文書 '.($job->id + 1).'] 法人営業  スコア 0.4000  ヒット 2')
            ->expectsOutput('   0.4000  求人票・必須条件 #0  法人営業の経験')
            ->expectsOutput('   0.3000  求人票・業務内容 #1  既存顧客への提案')
            ->doesntExpectOutputToContain('C001')
            ->assertSuccessful();
    }

    public function test_rag_search_は不正なオプションを_ap_i_を呼ばずに失敗にする(): void
    {
        foreach ([
            ['--type' => 'person'],
            ['--score' => 'sum'],
            ['--top-k' => 'abc'],
            ['--top-k' => '0'],
            ['--limit' => '0'],
            ['--type' => 'job', '--doc-type' => 'shokumu'],
            ['--top-k' => '1.5'],
            ['--limit' => '1001'],
            ['--min-similarity' => 'high'],
            ['question' => ''],
            ['question' => "  \n "],
        ] as $options) {
            $this->artisan('rag:search', ['question' => 'PHP', ...$options])->assertFailed();
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- JobMatcher / rag:match

    public function test_job_matcher_は検索に使う部分を見出しで取り出す(): void
    {
        ['job' => $job] = $this->seedSearchData();
        $matcher = app(JobMatcher::class);

        $this->assertSame("必須条件\n- PHP と Laravel の実務経験3年以上", $matcher->sourceText($job, JobMatcher::SOURCE_REQUIRED));
        $this->assertSame(
            "必須条件\n- PHP と Laravel の実務経験3年以上\n\n歓迎条件\n- 自動テストの経験",
            $matcher->sourceText($job, JobMatcher::SOURCE_REQUIRED_PREFERRED),
        );
        $this->assertSame(trim($this->jobMarkdown()), $matcher->sourceText($job, JobMatcher::SOURCE_FULL));
    }

    public function test_job_matcher_は求人票以外や見出しのない求人票を例外にする(): void
    {
        ['c1' => $c1] = $this->seedSearchData();
        $matcher = app(JobMatcher::class);

        $resume = $c1->documents()->where('type', Document::TYPE_RESUME)->first();
        $noHeading = $this->document(null, Document::TYPE_JOB, "# 求人票\n\n本文だけ");

        foreach ([
            fn () => $matcher->sourceText($resume, JobMatcher::SOURCE_REQUIRED),
            fn () => $matcher->sourceText($noHeading, JobMatcher::SOURCE_REQUIRED),
            fn () => $matcher->sourceText($noHeading, 'unknown'),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("{$i} 番目が例外にならなかった");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_job_matcher_は求人票の一部をベクトル化して候補者を並べる(): void
    {
        ['job' => $job, 'c1' => $c1] = $this->seedSearchData();

        $result = app(JobMatcher::class)->match($job, JobMatcher::SOURCE_REQUIRED, SearchOptions::fromConfig());

        // 埋め込み API には必須条件の部分だけが送られる
        $this->assertSame(["必須条件\n- PHP と Laravel の実務経験3年以上"], $this->embeddedTexts);
        $this->assertSame($result->text, $this->embeddedTexts[0]);
        // 求人票自身（0.95）は候補者の結果に入らない
        $this->assertSame($c1->id, $result->ranking->groups[0]->key);
        $this->assertSame(3, $result->ranking->totalGroups);
    }

    public function test_rag_match_は求人票に合う候補者を並べる(): void
    {
        ['job' => $job] = $this->seedSearchData();

        $this->artisan('rag:match', ['job_document_id' => $job->id, '--source' => 'required_preferred'])
            ->expectsOutput("求人票: [文書 {$job->id}] バックエンドエンジニア（PHP / Laravel）")
            ->expectsOutputToContain('検索に使った部分: required_preferred')
            ->expectsOutput('1. C001 架空 一郎  スコア 0.8200  ヒット 3（履歴書 1 / 職務経歴書 2）')
            ->expectsOutput('2. C002 架空 二郎  スコア 0.7400  ヒット 1（職務経歴書 1）')
            ->assertSuccessful();

        $this->assertStringContainsString('歓迎条件', $this->embeddedTexts[0]);
    }

    public function test_rag_match_は省略時に設定の_source_を使う(): void
    {
        ['job' => $job] = $this->seedSearchData();
        config(['rag.search.job_match_source' => 'full']);

        $this->artisan('rag:match', ['job_document_id' => $job->id])
            ->expectsOutputToContain('検索に使った部分: full')
            ->assertSuccessful();

        $this->assertSame(trim($this->jobMarkdown()), $this->embeddedTexts[0]);
    }

    public function test_rag_match_は文書がない_求人票でない_source_が不正なら_ap_i_を呼ばずに失敗する(): void
    {
        ['job' => $job, 'c1' => $c1] = $this->seedSearchData();
        $resume = $c1->documents()->first();

        $this->artisan('rag:match', ['job_document_id' => 999999])->assertFailed();
        $this->artisan('rag:match', ['job_document_id' => 'abc'])->expectsOutputToContain('文書が見つからない')->assertFailed();
        $this->artisan('rag:match', ['job_document_id' => $job->id, '--top-k' => '2.5'])->assertFailed();
        $this->artisan('rag:match', ['job_document_id' => $resume->id])->assertFailed();
        $this->artisan('rag:match', ['job_document_id' => $job->id, '--source' => 'all'])->assertFailed();
        $this->artisan('rag:match', ['job_document_id' => $job->id, '--score' => 'sum'])->assertFailed();

        Http::assertNothingSent();
    }
}
