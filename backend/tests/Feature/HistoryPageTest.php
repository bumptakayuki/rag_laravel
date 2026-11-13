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
 * 履歴（/history、POST /history/{id}/rerun）のテスト（RS-006）。
 *
 * 記録は画面から実行して作る（Http::fake）。
 */
class HistoryPageTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private Document $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        $c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $this->chunk($this->document($c1, Document::TYPE_CAREER_HISTORY), 0, 0.82, 'スキル', 'PHP と Laravel で8年開発した');
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);
        $this->chunk($this->document($c2, Document::TYPE_RESUME), 0, 0.60, '志望動機', 'PHP を学びたい');

        $this->job = $this->document(null, Document::TYPE_JOB, "# 求人票：バックエンド\n\n募集職種：バックエンドエンジニア\n\n## 必須条件\n\n- PHP\n\n## 歓迎条件\n\n- AWS", '求人票：バックエンド', 'jobs/J01.md');
        $this->chunk($this->job, 0, 0.90, '必須条件', '- PHP');
    }

    private function ask(): QueryLog
    {
        $this->post('/', ['scope' => 'candidate', 'q' => 'PHPの経験がある人', 'doc_type' => 'shokumu', 'top_k' => '3', 'min_similarity' => '0.5', 'score' => 'avg3']);

        return QueryLog::latest('id')->first();
    }

    private function searchJobs(): QueryLog
    {
        $this->post('/', ['scope' => 'job', 'q' => 'PHPの求人', 'top_k' => '2', 'min_similarity' => '0', 'score' => 'max']);

        return QueryLog::latest('id')->first();
    }

    private function match(): QueryLog
    {
        $this->post(route('jobs.match', $this->job), ['source' => 'full']);

        return QueryLog::latest('id')->first();
    }

    public function test_履歴がなければその旨を出す(): void
    {
        $this->get(route('history.index'))->assertOk()->assertSee('まだ履歴がありません');
    }

    public function test_新しい順に_質問_モード_条件_1位_スコア_該当_合計時間と操作を出す(): void
    {
        $ask = $this->ask();
        $jobs = $this->searchJobs();
        $match = $this->match();

        $response = $this->get(route('history.index'))->assertOk();
        $this->assertSame(3, substr_count($response->getContent(), 'data-testid="history-row"'));

        $response->assertSeeInOrder([
            // 求人票から探した記録（質問の代わりに求人票の名前）
            '求人票：バックエンドエンジニア', '求人→候補者', '5名 / ≥0 / 両方 / 最大値 / 全文', '架空 一郎', 'C001', '0.8200', '2名',
            // 求人票を探した記録
            'PHPの求人', '求人票検索', '2件 / ≥0 / 最大値', 'バックエンドエンジニア', 'J01.md', '0.9000', '1件',
            // 質問の記録（職務経歴書だけ・上位3平均）
            'PHPの経験がある人', '回答', '3名 / ≥0.5 / 職務経歴書 / 上位3平均', '架空 一郎', '0.8200', '1名',
        ]);
        $response->assertSee(' ms');

        // 詳細は記録を表示する画面へ
        $response->assertSee('href="'.e(route('search', ['query' => $ask->id])).'"', false);
        $response->assertSee('href="'.e(route('search', ['query' => $jobs->id])).'"', false);
        $response->assertSee('href="'.e(route('jobs.show', ['job' => $this->job->id, 'query' => $match->id])).'"', false);
        $response->assertSee('action="'.route('history.rerun', $ask).'"', false);
        $this->assertSame(3, substr_count($response->getContent(), 'data-loading="実行中…"'));
    }

    public function test_質問を同じ設定で再実行すると新しい記録を作って結果を表示する(): void
    {
        $original = $this->ask();
        Http::assertSentCount(2);

        $response = $this->post(route('history.rerun', $original));

        $rerun = QueryLog::latest('id')->first();
        $this->assertNotSame($original->id, $rerun->id);
        $response->assertRedirect(route('search', ['query' => $rerun->id]));
        $this->assertSame($original->question, $rerun->question);
        $this->assertSame(QueryLog::MODE_QUESTION, $rerun->mode);
        $this->assertEquals($original->params, $rerun->params);
        Http::assertSentCount(4); // 埋め込み + Claude をもう一度

        $this->get(route('search', ['query' => $rerun->id]))
            ->assertOk()
            ->assertSee('PHPの経験がある人')
            ->assertSee('C001 架空 一郎は PHP と Laravel で8年開発しています [1]。');
    }

    public function test_求人票の検索と求人マッチングも再実行できる(): void
    {
        $jobs = $this->searchJobs();
        $this->post(route('history.rerun', $jobs))->assertRedirect();
        $rerunJobs = QueryLog::latest('id')->first();
        $this->assertSame(QueryLog::MODE_JOB_SEARCH, $rerunJobs->mode);
        $this->assertEquals($jobs->params, $rerunJobs->params);

        $match = $this->match();
        $response = $this->post(route('history.rerun', $match));
        $rerunMatch = QueryLog::latest('id')->first();
        $response->assertRedirect(route('jobs.show', ['job' => $this->job->id, 'query' => $rerunMatch->id]));
        $this->assertSame(QueryLog::MODE_JOB_MATCH, $rerunMatch->mode);
        $this->assertSame('full', $rerunMatch->params['source']);
        $this->assertSame($this->job->id, $rerunMatch->source_document_id);

        $this->assertSame([], $this->claudeRequests);
    }

    public function test_元の求人票が消えた求人マッチングは再実行できず_詳細は質問画面で見る(): void
    {
        $match = $this->match();
        $this->job->delete();
        $match->refresh();
        Http::assertSentCount(1);

        $list = $this->get(route('history.index'))->assertOk();
        $list->assertSee('求人票：（削除済み）');
        $list->assertSee('href="'.e(route('search', ['query' => $match->id])).'"', false);

        $this->followingRedirects()
            ->from(route('history.index'))
            ->post(route('history.rerun', $match))
            ->assertOk()
            ->assertSee('元の求人票が取り込み直しで削除されているため、再実行できません。');
        Http::assertSentCount(1);

        // 詳細（質問画面）では、記録した結果を出す
        $this->get(route('search', ['query' => $match->id]))
            ->assertOk()
            ->assertSee('求人→候補者')
            ->assertSeeInOrder(['架空 一郎', '0.8200']);
    }

    public function test_再実行で外部_api_が失敗したら画面に出して記録しない(): void
    {
        $original = $this->ask();
        $this->claudeFails = true;

        $this->followingRedirects()
            ->from(route('history.index'))
            ->post(route('history.rerun', $original))
            ->assertOk()
            ->assertSee('回答生成（Claude）');

        $this->assertSame(1, QueryLog::count());
    }

    public function test_20件を超えると次のページに分かれる(): void
    {
        foreach (range(1, 21) as $i) {
            QueryLog::create([
                'mode' => QueryLog::MODE_QUESTION, 'question' => sprintf('質問%02d', $i), 'params' => [],
                'retrieved' => ['counts' => ['total_groups' => 0], 'candidates' => []], 'latency_ms' => [],
            ]);
        }

        $first = $this->get(route('history.index'))->assertOk();
        $this->assertSame(20, substr_count($first->getContent(), 'data-testid="history-row"'));
        $first->assertSee('質問21')->assertDontSee('質問01');
        $first->assertSee(e(route('history.index', ['page' => 2])), false);

        $second = $this->get(route('history.index', ['page' => 2]))->assertOk();
        $this->assertSame(1, substr_count($second->getContent(), 'data-testid="history-row"'));
        $second->assertSee('質問01');
    }

    public function test_存在しない記録の再実行は_404(): void
    {
        $this->post('/history/999/rerun')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_質問はエスケープして表示する(): void
    {
        $this->post('/', ['scope' => 'job', 'q' => '<script>alert(1)</script>', 'top_k' => '2', 'min_similarity' => '0', 'score' => 'max']);

        $this->get(route('history.index'))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
