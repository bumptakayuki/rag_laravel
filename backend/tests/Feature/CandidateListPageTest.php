<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 候補者一覧（/candidates?q=&job=&skill=&keyword=）のテスト。
 * RS-008: 一覧とコード・名前の絞り込み、ナビ。RS-009: 希望職種・スキル・キーワードの絞り込み（文字列の一致だけ）。
 */
class CandidateListPageTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private Candidate $c1;

    private Candidate $c2;

    private Candidate $c3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        // 登録順とコード順を変えて、コード順に並ぶことを確かめる
        $this->c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);
        $this->chunk($this->document($this->c2, Document::TYPE_CAREER_HISTORY,
            "# 職務経歴書\n\n希望職種：法人営業\n\n## スキル\n\n- 法人営業：5年\n- Excel：売上の集計"), 0, 0.5, 'スキル', '営業');

        $this->c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $shokumu = $this->document($this->c1, Document::TYPE_CAREER_HISTORY,
            "# 職務経歴書\n\n希望職種：バックエンドエンジニア\n\n## 職務要約\n\n5名のチームのリーダーを担当\n\n## スキル\n\n- PHP：10年\n- Laravel：8年\n- その他：Slack、Excel\n\n## 自己PR\n\nPython も少し触った");
        $this->chunk($shokumu, 0, 0.5, 'スキル', 'PHP');
        $this->chunk($shokumu, 1, 0.5, '自己PR', 'Laravel');
        $this->chunk($this->document($this->c1, Document::TYPE_RESUME,
            "# 履歴書\n\n希望職種：バックエンドエンジニア\n\n## 志望動機\n\nMySQL の改善を生かしたい"), 0, 0.5, '志望動機', '志望');

        // 文書がまだない候補者
        $this->c3 = Candidate::create(['code' => 'C010', 'name' => '仮名 100%_太郎']);

        // 求人票のチャンクは数えない
        $this->chunk($this->document(null, Document::TYPE_JOB, title: '求人票：営業'), 0, 0.5, '必須条件', '営業経験');
    }

    public function test_全候補者をコード順に_文書の有無_チャンク数_取り込み日時つきで出す(): void
    {
        $response = $this->get(route('candidates.index'))->assertOk();

        $response->assertSee('取り込み済み 3名・合計 4チャンク');
        $this->assertSame(3, substr_count($response->getContent(), 'data-testid="candidate-row"'));
        $response->assertSeeInOrder([
            'C001', '架空 一郎', 'バックエンドエンジニア', '職務経歴書', '履歴書', '3', $this->c1->documents->max('created_at')->format('Y-m-d H:i'),
            'C002', '架空 二郎', '法人営業', '職務経歴書', '履歴書なし', '1',
            'C010', '仮名 100%_太郎', '—', '職務経歴書なし', '履歴書なし', '0', '—',
        ]);
        // 候補者ごとのチャンク数（セルの中身そのもので確かめる）
        $response->assertSeeInOrder([
            'data-testid="chunk-count">3<', 'data-testid="chunk-count">1<', 'data-testid="chunk-count">0<',
        ], false);
        $this->assertSame([3, 1, 0], collect($response->viewData('rows'))->pluck('candidate.chunks_count')->all());
        // 詳細へのリンク。求人票は出さない。外部 API は呼ばない
        foreach ([$this->c1, $this->c2, $this->c3] as $candidate) {
            $response->assertSee('href="'.route('candidates.show', $candidate).'"', false);
        }
        $response->assertDontSee('求人票：営業');
        Http::assertNothingSent();
    }

    public function test_コードか名前の部分一致で絞り込む(): void
    {
        $byCode = $this->get(route('candidates.index', ['q' => 'c00']))->assertOk();
        $this->assertSame(2, substr_count($byCode->getContent(), 'data-testid="candidate-row"'));
        $byCode->assertSee('架空 一郎')->assertSee('架空 二郎')->assertDontSee('仮名 100%_太郎');
        $byCode->assertSee('value="c00"', false);
        // 件数の表示は絞り込み前の全体
        $byCode->assertSee('取り込み済み 3名・合計 4チャンク');

        $byName = $this->get(route('candidates.index', ['q' => ' 二郎 ']))->assertOk();
        $this->assertSame(1, substr_count($byName->getContent(), 'data-testid="candidate-row"'));
        $byName->assertSee('架空 二郎');
    }

    public function test_like_の特殊文字はそのままの文字として探す(): void
    {
        $percent = $this->get(route('candidates.index', ['q' => '%']))->assertOk();
        $this->assertSame(1, substr_count($percent->getContent(), 'data-testid="candidate-row"'));
        $percent->assertSee('仮名 100%_太郎');

        $underscore = $this->get(route('candidates.index', ['q' => '_']))->assertOk();
        $this->assertSame(1, substr_count($underscore->getContent(), 'data-testid="candidate-row"'));

        // バックスラッシュも LIKE のエスケープ文字として扱わない（該当なし）
        $backslash = $this->get(route('candidates.index', ['q' => '\\']))->assertOk();
        $this->assertSame(0, substr_count($backslash->getContent(), 'data-testid="candidate-row"'));
    }

    public function test_該当なしのときはその旨を出す(): void
    {
        $response = $this->get(route('candidates.index', ['q' => '存在しない']))->assertOk();

        $this->assertSame(0, substr_count($response->getContent(), 'data-testid="candidate-row"'));
        $response->assertSee('該当する候補者がいません');
    }

    public function test_候補者がいなければ0名と該当なしを出す(): void
    {
        Candidate::query()->delete();

        $response = $this->get(route('candidates.index'))->assertOk();

        $response->assertSee('取り込み済み 0名・合計 0チャンク');
        $response->assertSee('該当する候補者がいません');
    }

    public function test_配列の_q_は絞り込みなしとして扱う(): void
    {
        $response = $this->get('/candidates?q[]=x')->assertOk();

        $this->assertSame(3, substr_count($response->getContent(), 'data-testid="candidate-row"'));
    }

    public function test_希望職種は完全一致で絞り込み_選択肢は取り込み済みの希望職種(): void
    {
        $response = $this->get(route('candidates.index', ['job' => 'バックエンドエンジニア']))->assertOk();
        $this->assertSame(['C001'], $this->codes($response));
        $this->assertSame(['バックエンドエンジニア', '法人営業'], $response->viewData('jobOptions'));
        $response->assertSee('<option value="バックエンドエンジニア" selected>', false);
        $response->assertSee('1名が該当');

        // 部分一致では当たらない
        $this->assertSame([], $this->codes($this->get(route('candidates.index', ['job' => 'バックエンド']))));
        Http::assertNothingSent();
    }

    public function test_スキルはスキル欄に含まれる候補者を出し_一致した行を表示する(): void
    {
        // 大文字小文字は区別しない。職務経歴書の「スキル」欄だけを見る
        $response = $this->get(route('candidates.index', ['skill' => 'excel']))->assertOk();
        $this->assertSame(['C001', 'C002'], $this->codes($response));
        $response->assertSeeInOrder(['架空 一郎', 'その他：Slack、Excel', '架空 二郎', 'Excel：売上の集計']);
        $response->assertDontSee('PHP：10年');

        // スキル欄の外（自己PR）にだけある語では当たらない
        $this->assertSame([], $this->codes($this->get(route('candidates.index', ['skill' => 'Python']))));
        // スキルで絞り込まないときは行を出さない
        $this->assertStringNotContainsString('data-testid="skill-line"', $this->get(route('candidates.index'))->getContent());
    }

    public function test_キーワードは履歴書と職務経歴書の全文に含まれる候補者を出す(): void
    {
        $this->assertSame(['C001'], $this->codes($this->get(route('candidates.index', ['keyword' => 'リーダー']))));
        // 履歴書だけにある語。大文字小文字は区別しない
        $this->assertSame(['C001'], $this->codes($this->get(route('candidates.index', ['keyword' => 'mysql']))));
        $this->assertSame(['C001', 'C002'], $this->codes($this->get(route('candidates.index', ['keyword' => '希望職種']))));
        // 求人票の本文には当たらない
        $this->assertSame([], $this->codes($this->get(route('candidates.index', ['keyword' => '営業経験']))));
    }

    public function test_条件はすべて満たす候補者だけ出す(): void
    {
        $this->assertSame(['C002'], $this->codes($this->get(route('candidates.index', ['skill' => 'Excel', 'job' => '法人営業']))));
        $this->assertSame([], $this->codes($this->get(route('candidates.index', ['skill' => 'Excel', 'q' => 'C010']))));

        $response = $this->get(route('candidates.index', ['skill' => 'Excel', 'keyword' => 'リーダー', 'q' => '一郎']))->assertOk();
        $this->assertSame(['C001'], $this->codes($response));
        // 入力した条件は画面に残る
        $response->assertSee('value="Excel"', false)->assertSee('value="リーダー"', false)->assertSee('value="一郎"', false);
        $response->assertSee('条件をクリア');
    }

    public function test_履歴書だけにある希望職種とスキル欄も使い_同じスキル行は1回だけ出す(): void
    {
        $c4 = Candidate::create(['code' => 'C020', 'name' => '架空 四郎']);
        // 職務経歴書には希望職種の行がない。スキル欄は両方の文書に同じ行がある
        $this->document($c4, Document::TYPE_CAREER_HISTORY, "# 職務経歴書\n\n## スキル\n\n- SQL：5年");
        $this->document($c4, Document::TYPE_RESUME, "# 履歴書\n\n希望職種：データエンジニア\n\n## スキル\n\n- SQL：5年\n- dbt：1年");

        $response = $this->get(route('candidates.index', ['skill' => 'sql']))->assertOk();
        $this->assertSame(['C020'], $this->codes($response));
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="skill-line">SQL：5年<'));
        $this->assertSame(['C020'], $this->codes($this->get(route('candidates.index', ['skill' => 'dbt']))));

        // 希望職種は職務経歴書になければ履歴書から取る
        $this->assertSame(['C020'], $this->codes($this->get(route('candidates.index', ['job' => 'データエンジニア']))));
    }

    public function test_ナビに候補者があり_一覧と詳細で選択中になる(): void
    {
        $this->assertTrue($this->navActive(route('candidates.index')));
        $this->assertTrue($this->navActive(route('candidates.show', $this->c1)));
        // 質問画面では検索が選択中（候補者は選択中にしない）
        $this->assertFalse($this->navActive(route('search')));
    }

    /** @return list<string> 一覧に出た候補者のコード（表示順） */
    private function codes(TestResponse $response): array
    {
        return collect($response->assertOk()->viewData('rows'))->pluck('candidate.code')->all();
    }

    /** その画面のナビで「候補者」が選択中（aria-current="page"）か */
    private function navActive(string $url): bool
    {
        $html = $this->get($url)->assertOk()->assertSee('>候補者</a>', false)->getContent();

        return preg_match('#<a href="'.preg_quote(route('candidates.index'), '#').'"\s+aria-current="page"#', $html) === 1;
    }
}
