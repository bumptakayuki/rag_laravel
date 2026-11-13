<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Document;
use App\Models\QueryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 求人票一覧（/jobs）と求人票詳細（/jobs/{id}、POST /jobs/{id}/match）のテスト（RS-006）。
 */
class JobPagesTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private const BACKEND = <<<'MD'
        # 求人票：バックエンドエンジニア（PHP / Laravel）

        会社名：株式会社サンプルクラウド
        募集職種：バックエンドエンジニア（PHP / Laravel）

        ## 業務内容

        API の設計と実装

        ## 必須条件

        - PHP と Laravel を用いた実務経験が3年以上ある方
        - Git を使ったチーム開発の経験

        ## 歓迎条件

        - AWS の運用経験
        MD;

    private Document $backend;

    private Document $sales;

    private Candidate $c1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        $this->backend = $this->document(null, Document::TYPE_JOB, self::BACKEND, '求人票：バックエンドエンジニア（PHP / Laravel）', 'jobs/J01.md');
        $this->chunk($this->backend, 0, 0.3, '業務内容', 'API の設計と実装');
        $this->chunk($this->backend, 1, 0.3, '必須条件', "- PHP と Laravel を用いた実務経験が3年以上ある方\n- Git を使ったチーム開発の経験");
        $this->chunk($this->backend, 2, 0.3, '歓迎条件', '- AWS の運用経験');

        $this->sales = $this->document(null, Document::TYPE_JOB, "# 求人票：法人営業\n\n## 必須条件\n\n1. 法人営業の経験3年以上", '求人票：法人営業', 'jobs/J02.md');
        $this->chunk($this->sales, 0, 0.3, '必須条件', '1. 法人営業の経験3年以上');

        $this->c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $this->chunk($this->document($this->c1, Document::TYPE_CAREER_HISTORY), 0, 0.82, 'スキル', 'PHP と Laravel で8年開発した');
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);
        $this->chunk($this->document($c2, Document::TYPE_CAREER_HISTORY), 0, 0.40, 'スキル', '営業の経験');
    }

    public function test_一覧に職種名_文書名_主な必須スキル_チャンク数_取り込み日時を出す(): void
    {
        $response = $this->get(route('jobs.index'))->assertOk();

        $response->assertSee('取り込み済み 2件・合計 4チャンク');
        $this->assertSame(2, substr_count($response->getContent(), 'data-testid="job-row"'));
        $response->assertSeeInOrder([
            'バックエンドエンジニア（PHP / Laravel）', 'jobs/J01.md', 'PHP と Laravel を用いた実務経験が3年以上ある方', '3', $this->backend->created_at->format('Y-m-d H:i'),
            '法人営業', 'jobs/J02.md', '法人営業の経験3年以上', '1',
        ]);
        // 候補者の文書は出さない。詳細へのリンクがある
        $response->assertDontSee('架空 一郎');
        $response->assertSee('href="'.route('jobs.show', $this->backend).'"', false);
        Http::assertNothingSent();
    }

    public function test_職種名で絞り込める(): void
    {
        $this->get(route('jobs.index', ['q' => 'バックエンド']))
            ->assertOk()
            ->assertSee('value="バックエンド"', false)
            ->assertSee('jobs/J01.md')
            ->assertDontSee('jobs/J02.md');

        $this->get(route('jobs.index', ['q' => 'デザイナー']))
            ->assertOk()
            ->assertSee('該当する求人票がありません');

        // LIKE の特殊文字はそのままの文字として探す（% で全件にならない）
        $this->get(route('jobs.index', ['q' => '%']))->assertOk()->assertSee('該当する求人票がありません');
        // 配列は絞り込みなし
        $this->get(route('jobs.index', ['q' => ['x']]))->assertOk()->assertSee('jobs/J02.md');
    }

    public function test_詳細は全文を見出しごとに出し_各見出しのチャンクと検索に使う部分を示す(): void
    {
        $response = $this->get(route('jobs.show', $this->backend))->assertOk();

        $response->assertSeeInOrder(['バックエンドエンジニア（PHP / Laravel）', 'jobs/J01.md', '3チャンク']);
        $response->assertSeeInOrder([
            '業務内容', 'chunk 0', 'API の設計と実装',
            '必須条件', 'chunk 1', '検索に使用', 'PHP と Laravel を用いた実務経験が3年以上ある方',
            '歓迎条件', 'chunk 2', 'AWS の運用経験',
        ]);
        // 初期値（required）では必須条件だけを使う
        $this->assertSame(1, substr_count($response->getContent(), '>検索に使用<'));
        $response->assertSee('value="required" class="peer sr-only" checked', false);
        $response->assertSeeInOrder(['質問の代わりに使う文（必須条件のみ', 'data-testid="source-text"', '必須条件', 'Git を使ったチーム開発の経験'], false);
        $response->assertSee('この求人に合う候補者を探す');
        $response->assertSee('data-loading="候補者を探しています…"', false);
        $response->assertDontSee('data-testid="search-flow"', false);
        Http::assertNothingSent();
    }

    public function test_候補者を探すと記録して詳細に戻り_候補者ランキングと候補者詳細へのリンクを出す(): void
    {
        $response = $this->post(route('jobs.match', $this->backend), ['source' => 'required_preferred']);

        $log = QueryLog::sole();
        $response->assertRedirect(route('jobs.show', ['job' => $this->backend->id, 'query' => $log->id]));
        $this->assertSame(QueryLog::MODE_JOB_MATCH, $log->mode);
        $this->assertSame($this->backend->id, $log->source_document_id);
        $this->assertSame('required_preferred', $log->params['source']);
        // 必須＋歓迎の文で埋め込みを作った（Claude は呼ばない）
        $this->assertStringContainsString('歓迎条件', $this->embeddingRequests[0]['input'][0]);
        $this->assertSame([], $this->claudeRequests);

        $page = $this->get(route('jobs.show', ['job' => $this->backend, 'query' => $log->id]))->assertOk();
        $page->assertSee('data-testid="search-flow"', false);
        $page->assertSeeInOrder(['候補者ランキング', '#1', '架空 一郎', '0.8200', '#2', '架空 二郎', '0.4000']);
        $page->assertSee(e(route('candidates.show', ['candidate' => $this->c1->id, 'query' => $log->id])), false);
        // 実行したときの「検索に使う部分」がフォームとハイライトに出る
        $page->assertSee('value="required_preferred" class="peer sr-only" checked', false);
        $this->assertSame(2, substr_count($page->getContent(), '>検索に使用<'));
        $page->assertDontSee('data-testid="answer"', false);
    }

    public function test_検索に使う部分が不正なら_api_を呼ばずにエラーを出す(): void
    {
        $this->followingRedirects()
            ->from(route('jobs.show', $this->backend))
            ->post(route('jobs.match', $this->backend), ['source' => 'all'])
            ->assertOk()
            ->assertSee('検索に使う部分の値が正しくありません。');

        Http::assertNothingSent();
        $this->assertSame(0, QueryLog::count());
    }

    public function test_見出しがない求人票では理由を出し_実行してもエラーにする(): void
    {
        $noHeading = $this->document(null, Document::TYPE_JOB, "# 求人票：事務\n\n事務の仕事", '求人票：事務');

        $this->get(route('jobs.show', $noHeading))->assertOk()->assertSee('求人票に見出し「必須条件」がない');

        $this->followingRedirects()
            ->from(route('jobs.show', $noHeading))
            ->post(route('jobs.match', $noHeading), ['source' => 'required'])
            ->assertOk()
            ->assertSee('data-testid="api-error"', false)
            ->assertSee('実行できませんでした');
        Http::assertNothingSent();
    }

    public function test_外部_api_の失敗は画面に出し記録しない(): void
    {
        $this->embeddingFails = true;

        $this->followingRedirects()
            ->from(route('jobs.show', $this->backend))
            ->post(route('jobs.match', $this->backend), ['source' => 'required'])
            ->assertOk()
            ->assertSee('質問のベクトル化（OpenAI）')
            ->assertSee('invalid model');

        $this->assertSame(0, QueryLog::count());
    }

    public function test_求人票でない文書_他の求人票の記録は_404(): void
    {
        $resume = $this->document($this->c1, Document::TYPE_RESUME);
        $this->get(route('jobs.show', $resume))->assertNotFound();
        $this->post(route('jobs.match', $resume), ['source' => 'required'])->assertNotFound();
        $this->get('/jobs/999')->assertNotFound();

        $this->post(route('jobs.match', $this->backend), ['source' => 'required']);
        $log = QueryLog::sole();
        $this->get(route('jobs.show', ['job' => $this->sales, 'query' => $log->id]))->assertNotFound();

        // 質問の記録は求人票詳細では開けない
        $this->post('/', ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max']);
        $question = QueryLog::where('mode', QueryLog::MODE_QUESTION)->sole();
        $this->get(route('jobs.show', ['job' => $this->backend, 'query' => $question->id]))->assertNotFound();
    }
}
