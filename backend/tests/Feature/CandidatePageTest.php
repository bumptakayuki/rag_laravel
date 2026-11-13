<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 候補者詳細（/candidates/{id}?query={id}）のテスト（RS-006）。タブの切り替えは画面の JavaScript（ここでは確認できない）。
 *
 * 検索は質問画面から実行して記録を作り（Http::fake）、その記録 ID で詳細を開く。
 */
class CandidatePageTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private Candidate $c1;

    private Chunk $skill;

    private Chunk $pr2;

    private Chunk $motive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        $this->c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);

        $shokumu = $this->document($this->c1, Document::TYPE_CAREER_HISTORY,
            "# 職務経歴書\n\n## 職務経歴\n\n### 株式会社サンプル\n\nLaravel で SaaS を開発。\n\n## 自己PR\n\nあいうえおかきくけこ\n\nさしすせそ",
            sourcePath: 'candidates/C001/shokumu.md');
        $this->skill = $this->chunk($shokumu, 0, 0.82, '株式会社サンプル', 'Laravel で SaaS を開発。', 3);
        $this->chunk($shokumu, 1, 0.30, '自己PR', 'あいうえおかきくけこ', 3);
        // 先頭3文字「くけこ」は前のチャンクとの重なり（全文では1回だけ出す）
        $this->pr2 = $this->chunk($shokumu, 2, 0.61, '自己PR', 'くけこさしすせそ', 3);

        $resume = $this->document($this->c1, Document::TYPE_RESUME, "# 履歴書\n\n## 志望動機\n\nPHP を軸にキャリアを積みたい", sourcePath: 'candidates/C001/rirekisho.md');
        $this->motive = $this->chunk($resume, 0, 0.55, '志望動機', 'PHP を軸にキャリアを積みたい');

        // 別の候補者（この候補者の詳細には出ない）
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);
        $this->chunk($this->document($c2, Document::TYPE_CAREER_HISTORY), 0, 0.90, 'スキル', '二郎の本文');
    }

    private function ask(float $minSimilarity = 0.5): QueryLog
    {
        $this->post('/', ['scope' => 'candidate', 'q' => 'PHPの経験', 'top_k' => '5', 'min_similarity' => (string) $minSimilarity, 'score' => 'max']);

        return QueryLog::sole();
    }

    public function test_職務経歴書と履歴書の全文を両方_見出しつきで出し_重なりは1回だけ出す(): void
    {
        $response = $this->get(route('candidates.show', $this->c1))->assertOk();

        $response->assertSeeInOrder(['架空 一郎', 'C001']);
        // タブ（職務経歴書 → 履歴書）と、それぞれの中身（タブの切り替えは画面の JavaScript が行う）
        $response->assertSeeInOrder(['data-tab="shokumu"', '職務経歴書', 'data-tab="rirekisho"', '履歴書'], false);
        $response->assertSeeInOrder([
            'data-panel="shokumu"', 'candidates/C001/shokumu.md',
            '職務経歴書', '職務経歴', '株式会社サンプル', 'chunk 0', 'Laravel で SaaS を開発。',
            '自己PR', 'chunk 1', 'あいうえおかきくけこ', 'chunk 2', 'さしすせそ',
            'data-panel="rirekisho"', 'candidates/C001/rirekisho.md', '志望動機', 'chunk 0', 'PHP を軸にキャリアを積みたい',
        ], false);
        $this->assertSame(0, substr_count($response->getContent(), 'くけこさしすせそ'));
        // 検索をしていないのでハイライトもヒット一覧も出さない
        $response->assertDontSee('data-testid="hit-chunk"', false);
        $response->assertDontSee('ヒット箇所（類似度順）');
        // 別の候補者の文書は出さない
        $response->assertDontSee('二郎の本文');
    }

    public function test_タブは画面の_javascript_で切り替え_サーバーは_tab_を見ない(): void
    {
        $default = $this->get(route('candidates.show', $this->c1))->assertOk()->getContent();
        $withTab = $this->get(route('candidates.show', ['candidate' => $this->c1, 'tab' => Document::TYPE_RESUME]))->assertOk()->getContent();

        // ?tab= を付けても同じ画面（どちらを開くかはブラウザが決める）
        $this->assertSame($default, $withTab);
        $this->assertStringContainsString('role="tablist"', $default);
        $this->assertStringContainsString('aria-controls="panel-rirekisho"', $default);
        $this->assertStringContainsString("closest('[data-panel]')", $default);
    }

    public function test_query_を渡すとその検索のヒット箇所をハイライトし_類似度と資料番号を出す(): void
    {
        $log = $this->ask(0.5);

        $response = $this->get(route('candidates.show', ['candidate' => $this->c1, 'query' => $log->id]))->assertOk();

        // 下限 0.5 以上: 職務経歴書 0.82・0.61、履歴書 0.55（0.30 は出さない）
        $this->assertSame(3, substr_count($response->getContent(), 'data-testid="hit-chunk"'));
        $response->assertSee('id="chunk-'.$this->skill->id.'"', false);
        // 資料番号は順位の順に通しで振る（1位の C002 が [1]、C001 は [2] から）
        $response->assertSeeInOrder(['ヒット 類似度 0.8200', '資料 [2]', 'Laravel で SaaS を開発。']);
        $response->assertSeeInOrder(['ヒット 類似度 0.6100', '資料 [3]', 'さしすせそ']);
        $response->assertSeeInOrder(['data-panel="rirekisho"', 'ヒット 類似度 0.5500', '資料 [4]', 'PHP を軸にキャリアを積みたい'], false);
        $response->assertDontSee('0.3000');

        // 概要: 順位・スコア・ヒット数、タブのヒット数
        $response->assertSeeInOrder(['スコア', '0.8200', '順位', '#2', 'ヒット', '3件']);
        $response->assertSeeInOrder(['職務経歴書', 'ヒット 2', '履歴書', 'ヒット 1']);
        $response->assertSee('「PHPの経験」でのヒット箇所を表示中');
        $response->assertSee('href="'.e(route('search', ['query' => $log->id])).'"', false);
    }

    public function test_ヒット一覧は類似度順で_同じ画面の該当箇所へのリンクになる(): void
    {
        $log = $this->ask(0.5);

        $response = $this->get(route('candidates.show', ['candidate' => $this->c1, 'query' => $log->id]))->assertOk();

        // 先頭（一番近いヒット）のタブを最初に開く。押すと JavaScript がそのチャンクのタブを開く
        $response->assertSeeInOrder([
            'ヒット箇所（類似度順）', 'data-hit-list',
            'href="#chunk-'.$this->skill->id.'"', '0.8200',
            'href="#chunk-'.$this->pr2->id.'"', '0.6100',
            'href="#chunk-'.$this->motive->id.'"', '0.5500',
        ], false);
    }

    public function test_上位に入らなかった候補者は圏外と出す(): void
    {
        $this->post('/', ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '1', 'min_similarity' => '0', 'score' => 'max']);
        $log = QueryLog::sole();

        $this->get(route('candidates.show', ['candidate' => $this->c1, 'query' => $log->id]))
            ->assertOk()
            ->assertSeeInOrder(['順位', '圏外'])
            // 上位に入らなくても、ヒットしたチャンクは出す
            ->assertSee('data-testid="hit-chunk"', false);
    }

    public function test_文書がない種別のタブにはその旨を出す(): void
    {
        $c3 = Candidate::create(['code' => 'C003', 'name' => '架空 三郎']);
        $this->document($c3, Document::TYPE_CAREER_HISTORY, '## A');

        $this->get(route('candidates.show', $c3))
            ->assertOk()
            ->assertSeeInOrder(['data-panel="rirekisho"', 'この候補者の履歴書は取り込まれていません。'], false);
    }

    public function test_候補者や記録がなければ_404(): void
    {
        $this->get('/candidates/999')->assertNotFound();
        $this->get(route('candidates.show', ['candidate' => $this->c1, 'query' => 999]))->assertNotFound();
        $this->get(route('candidates.show', ['candidate' => $this->c1, 'query' => 'abc']))->assertNotFound();
    }

    public function test_名前と本文はエスケープして表示する(): void
    {
        $c4 = Candidate::create(['code' => 'C004', 'name' => '<b>四郎</b>']);
        $this->chunk($this->document($c4, Document::TYPE_CAREER_HISTORY, "## <i>見出し</i>\n\n<script>x</script>"), 0, 0.5, '<i>見出し</i>', '<script>x</script>');

        $this->get(route('candidates.show', $c4))
            ->assertOk()
            ->assertDontSee('<b>四郎</b>', false)
            ->assertDontSee('<script>x', false)
            ->assertDontSee('<i>見出し</i>', false)
            ->assertSee('&lt;script&gt;x&lt;/script&gt;', false);
    }
}
