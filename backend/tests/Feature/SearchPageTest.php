<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 質問画面（/）のテスト（RS-004b で検索部分、RS-006 で回答・記録・履歴からの表示）。
 *
 * 実行は POST /。queries に記録して GET /?query={id} にリダイレクトし、記録から結果を組み立て直して表示する。
 * 埋め込み API・Claude API は Http::fake() で偽装する（FakesRagApis）。
 */
class SearchPageTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    private Candidate $c1;

    private Document $job;

    private Chunk $topChunk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        $this->c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);

        $shokumu = $this->document($this->c1, Document::TYPE_CAREER_HISTORY);
        $this->topChunk = $this->chunk($shokumu, 0, 0.82, 'スキル', 'PHP と Laravel で8年開発した');
        $this->chunk($shokumu, 1, 0.40, '自己PR', 'チームで設計を進めた');
        $this->chunk($this->document($this->c1, Document::TYPE_RESUME), 3, 0.55, '志望動機', 'PHP を軸にキャリアを積みたい');
        $this->chunk($this->document($c2, Document::TYPE_CAREER_HISTORY), 0, 0.74, 'スキル', 'PHP のフレームワークで EC サイトを開発');

        $this->job = $this->document(null, Document::TYPE_JOB, "# 求人票：バックエンド\n\n募集職種：バックエンドエンジニア（PHP / Laravel）\n\n## 必須条件\n\n- PHP", 'バックエンドエンジニア（PHP / Laravel）');
        $this->chunk($this->job, 4, 0.95, '必須条件', 'PHP と Laravel の実務経験3年以上');
        $this->chunk($this->job, 6, 0.60, '歓迎条件', '自動テストの経験');
    }

    /** 実行して、リダイレクト先（記録の表示）まで進んだ画面 */
    private function submit(array $input): TestResponse
    {
        return $this->followingRedirects()->from('/')->post('/', $input);
    }

    public function test_最初の表示はフォームだけで_api_を呼ばない(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('検索して回答する')
            ->assertSee('質問')
            ->assertSee('表示する件数')
            ->assertSee('類似度の下限')
            ->assertSee('検索する文書（候補者のみ）')
            ->assertSee('スコアの決め方')
            ->assertSee('name="_token"', false)
            // 送信中はボタンにスピナーを出す（動きはレイアウトの JavaScript。ここでは仕組みがあることだけ確かめる）
            ->assertSee('data-loading="検索・回答を作成しています…"', false)
            ->assertSee('id="loading-label"', false)
            ->assertSee('animate-spin', false)
            ->assertDontSee('data-testid="search-flow"', false);

        Http::assertNothingSent();
    }

    public function test_実行すると記録して_記録を表示する画面へリダイレクトし_再読み込みで_api_を呼ばない(): void
    {
        $response = $this->from('/')->post('/', ['scope' => 'candidate', 'q' => 'PHPの経験がある人', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max']);

        $log = QueryLog::sole();
        $response->assertRedirect(route('search', ['query' => $log->id]));
        $this->assertSame(QueryLog::MODE_QUESTION, $log->mode);
        $this->assertSame('PHPの経験がある人', $log->question);
        Http::assertSentCount(2); // 埋め込み + Claude

        // 表示（GET）だけなら外部 API は呼ばない
        $this->get(route('search', ['query' => $log->id]))->assertOk()->assertSee('data-testid="search-flow"', false);
        $this->get(route('search', ['query' => $log->id]))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_候補者を検索すると1人1カードでスコア_バッジ_抜粋が並ぶ(): void
    {
        $response = $this->submit(['scope' => 'candidate', 'q' => 'PHPの経験がある人', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])->assertOk();

        // 候補者の順位（C001 0.82 → C002 0.74）とカードの数
        $response->assertSeeInOrder(['#1', '架空 一郎', 'C001', '0.8200', '#2', '架空 二郎', 'C002', '0.7400']);
        $this->assertSame(2, substr_count($response->getContent(), 'data-testid="candidate-card"'));

        // ヒット数と文書種別のバッジ（職務経歴書 → 履歴書の順）、最も近いチャンクの抜粋
        $response->assertSeeInOrder(['ヒット 3件', '職務経歴書 2', '履歴書 1', 'PHP と Laravel で8年開発した']);

        // 展開部分: 文書別に見出し・chunk_index・類似度・本文
        $response->assertSeeInOrder([
            '職務経歴書', '2件ヒット',
            'スキル', 'chunk 0', '0.8200', 'PHP と Laravel で8年開発した',
            '自己PR', 'chunk 1', '0.4000', 'チームで設計を進めた',
            '履歴書', '1件ヒット',
            '志望動機', 'chunk 3', '0.5500', 'PHP を軸にキャリアを積みたい',
        ]);

        // 求人票は候補者の結果に出ない
        $response->assertDontSee('PHP と Laravel の実務経験3年以上');
    }

    public function test_展開したカードの各チャンクから候補者詳細の該当箇所へ移動できる(): void
    {
        $response = $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])->assertOk();
        $log = QueryLog::sole();

        // タブは候補者詳細の JavaScript が #chunk-{id} から決めるので、URL に tab は付けない
        $url = route('candidates.show', ['candidate' => $this->c1->id, 'query' => $log->id]).'#chunk-'.$this->topChunk->id;
        $response->assertSee('href="'.e($url).'"', false);
        $this->assertSame(4, substr_count($response->getContent(), 'data-testid="chunk-link"'));
        $response->assertSee('候補者詳細を開く');
    }

    public function test_回答と資料の対応と送信したプロンプト全文を出す(): void
    {
        $response = $this->submit(['scope' => 'candidate', 'q' => 'PHPの経験がある人', 'top_k' => '5', 'min_similarity' => '0.5', 'score' => 'max'])->assertOk();

        $response->assertSee('data-testid="answer"', false);
        $response->assertSeeInOrder(['回答', 'C001 架空 一郎は PHP と Laravel で8年開発しています [1]。']);
        // 資料: C001 の 0.82・0.55、C002 の 0.74（候補者ごとに最大3件、番号は通し）
        $response->assertSeeInOrder(['資料番号の対応（3 件）', '[1]', 'C001 架空 一郎', '職務経歴書・スキル', '[2]', 'C001 架空 一郎', '履歴書・志望動機', '[3]', 'C002 架空 二郎']);
        $response->assertSeeInOrder(['送信したプロンプト全文', '[system]', '[user]', 'PHPの経験がある人']);

        // 記録した全文と同じものを表示している
        $this->assertStringContainsString('[system]', QueryLog::sole()->prompt);
        $this->assertCount(1, $this->claudeRequests);
    }

    public function test_回答に送っていない資料番号があれば注意を出す(): void
    {
        $this->claudeAnswer = 'C001 です [1][9]。';

        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])
            ->assertOk()
            ->assertSee('送っていない資料番号 [9]');
    }

    public function test_検索の流れに件数_所要時間_実行sqlが出る(): void
    {
        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0.5', 'score' => 'max'])
            ->assertOk()
            ->assertSee('data-testid="search-flow"', false)
            ->assertSee('質問を 1536 個の数値に')
            ->assertSee('近い順に 4 件取得')
            ->assertSee('3 文書・4 チャンクが対象')
            ->assertSee('3 件 → 2 名 → 上位 2 名')
            ->assertSee('下限 0.5 未満の 1 件を除外')
            ->assertSee('4 回答生成')
            ->assertSee('2 名×最大 3 件を Claude へ')
            ->assertSee('資料 3 件、うち 1 件を引用')
            ->assertSee(' ms')
            ->assertSeeInOrder(['工程ごとの所要時間', 'ベクトル化', 'チャンク検索', '集約', 'プロンプト組み立て', '回答生成', '合計'])
            ->assertSee('ORDER BY c.embedding &lt;=&gt; :q', false)
            ->assertSee('LIMIT :limit')
            ->assertSee(':limit = 50');
    }

    public function test_文書種別_top_k_スコア方法を指定できる(): void
    {
        $response = $this->submit([
            'scope' => 'candidate', 'q' => 'PHP', 'doc_type' => 'rirekisho', 'top_k' => '1', 'min_similarity' => '0', 'score' => 'max_plus_hits',
        ])->assertOk();

        $response->assertSee('候補者ランキング（上位 1 名 / 該当 1 名）');
        $response->assertSee('最大類似度 ＋ 2件目以降のヒット1件につき', false);
        $response->assertSee('d.type = :type');
        $response->assertSee(':type = rirekisho');
        $response->assertSeeInOrder(['架空 一郎', '0.5500']);
        $response->assertDontSee('架空 二郎');
        // 履歴書だけに絞ったので、展開部分に職務経歴書の欄は出ない
        $response->assertDontSee('職務経歴書 ');

        // フォームには実行した条件が入っている
        $response->assertSee('value="rirekisho" selected', false);
        $response->assertSee('value="max_plus_hits" class="peer sr-only" checked', false);
        // jsonb はキーの順番を保たないので、順番を問わずに比べる
        $this->assertEquals(['chunk_limit' => 50, 'top_k' => 1, 'min_similarity' => 0.0, 'score' => 'max_plus_hits', 'document_type' => 'rirekisho', 'chunks_per_candidate' => 3], QueryLog::sole()->params);
    }

    public function test_求人票を検索すると回答を作らずに記録し_1求人1カードで職種名と見出しごとの類似度が出る(): void
    {
        $response = $this->submit(['scope' => 'job', 'q' => 'PHPの経験がある人', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="job-card"'));
        $response->assertSee('求人票ランキング（上位 1 件 / 該当 1 件）');
        $response->assertSeeInOrder([
            '#1', 'バックエンドエンジニア（PHP / Laravel）', "文書 ID {$this->job->id}", 'ヒット 2件',
            '必須条件', '0.9500', '歓迎条件', '0.6000',
            'PHP と Laravel の実務経験3年以上', '0.9500',
        ]);
        $response->assertDontSee('架空 一郎');
        $response->assertDontSee('data-testid="answer"', false);

        // カードから求人票詳細へ移動できる
        $response->assertSee('href="'.route('jobs.show', $this->job).'"', false);
        $response->assertSee('求人票を開く');

        $log = QueryLog::sole();
        $this->assertSame(QueryLog::MODE_JOB_SEARCH, $log->mode);
        $this->assertSame($this->job->id, $log->retrieved['jobs'][0]['document_id']);
        $this->assertNull($log->prompt);
        $this->assertSame([], $this->claudeRequests);
    }

    public function test_下限以上のヒットがなければ_claude_を呼ばずにその旨を出す(): void
    {
        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0.99', 'score' => 'max'])
            ->assertOk()
            ->assertSee('下限以上のヒットがありません')
            ->assertSee('資料からはわかりません');

        $this->assertSame([], $this->claudeRequests);
    }

    public function test_外部_api_の失敗は工程_ステータス_メッセージを画面に出し_記録しない(): void
    {
        $this->embeddingFails = true;

        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])
            ->assertOk()
            ->assertSee('data-testid="api-error"', false)
            ->assertSee('質問のベクトル化（OpenAI）')
            ->assertSee('400')
            ->assertSee('invalid model')
            // 入力は残っている
            ->assertSee('>PHP</textarea>', false);

        $this->assertSame(0, QueryLog::count());
        $this->assertSame([], $this->claudeRequests);
    }

    public function test_claude_の失敗も画面に出す(): void
    {
        $this->claudeFails = true;

        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])
            ->assertOk()
            ->assertSee('回答生成（Claude）')
            ->assertSee('bad request');

        $this->assertSame(0, QueryLog::count());
    }

    public function test_記録に失敗しても結果はその場で表示し_履歴に残らないことを知らせる(): void
    {
        // 保存だけを失敗させる（検索・生成は通る）
        DB::statement('ALTER TABLE queries RENAME TO queries_renamed');

        $response = $this->from('/')->post('/', ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])
            ->assertOk()
            ->assertSee('data-testid="record-error"', false)
            ->assertSee('relation &quot;queries&quot; does not exist', false)
            ->assertSee('C001 架空 一郎は PHP と Laravel で8年開発しています [1]。')
            ->assertSee('data-testid="candidate-card"', false);

        // 記録がないので、候補者詳細へのリンクには query を付けない
        $response->assertDontSee('query=', false);
        $this->assertCount(1, $this->claudeRequests);
    }

    public function test_求人票から探した記録を質問画面で開いても_フォームには入れない(): void
    {
        $text = str_repeat('求人票の文', 300); // 1500 文字（質問の上限 1000 を超える）
        $log = QueryLog::create([
            'mode' => QueryLog::MODE_JOB_MATCH, 'question' => $text,
            'params' => ['top_k' => 2, 'min_similarity' => 0, 'score' => 'avg3', 'source' => 'full'],
            'retrieved' => ['counts' => ['retrieved' => 0, 'kept' => 0, 'total_groups' => 0], 'chunks' => [], 'candidates' => []],
            'latency_ms' => ['embed' => 1.0],
        ]);

        $response = $this->get(route('search', ['query' => $log->id]))->assertOk();

        $response->assertSee('求人→候補者');
        $response->assertSee('></textarea>', false);
        $response->assertSee('value="max" class="peer sr-only" checked', false);
        Http::assertNothingSent();
    }

    public function test_表示する記録がなければ_404(): void
    {
        $this->get('/?query=999')->assertNotFound();
        $this->get('/?query=abc')->assertNotFound();
        $this->get('/?'.http_build_query(['query' => ['1']]))->assertNotFound();
    }

    public function test_取り込み直しで消えたチャンクは出さずに数を知らせる(): void
    {
        $this->submit(['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])->assertOk();
        $log = QueryLog::sole();
        $this->topChunk->delete();

        $response = $this->get(route('search', ['query' => $log->id]))
            ->assertOk()
            ->assertSee('記録したチャンクのうち 1 件は、取り込み直しで削除されているため表示していません')
            // スコアは記録した値のまま。抜粋は残っているチャンクのうち一番近いもの
            ->assertSeeInOrder(['架空 一郎', 'PHP を軸にキャリアを積みたい', '0.8200']);
        // 消えたチャンクの本文は、記録したプロンプト全文の中にだけ残る（カードには出ない）
        $this->assertSame(1, substr_count($response->getContent(), 'PHP と Laravel で8年開発した'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        $base = ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'];

        return [
            '質問が空' => [[...$base, 'q' => ''], 'q'],
            '検索対象が不正' => [[...$base, 'scope' => 'person'], 'scope'],
            'top_k が0' => [[...$base, 'top_k' => '0'], 'top_k'],
            'top_k が小数' => [[...$base, 'top_k' => '1.5'], 'top_k'],
            '下限が範囲外' => [[...$base, 'min_similarity' => '2'], 'min_similarity'],
            '下限が数値でない' => [[...$base, 'min_similarity' => 'high'], 'min_similarity'],
            '文書種別が不正' => [[...$base, 'doc_type' => 'job'], 'doc_type'],
            'スコア方法が不正' => [[...$base, 'score' => 'sum'], 'score'],
            '求人票で文書種別を指定' => [[...$base, 'scope' => 'job', 'doc_type' => 'shokumu'], 'doc_type'],
            '質問が空白だけ' => [[...$base, 'q' => "  \n "], 'q'],
            '質問が1001文字' => [[...$base, 'q' => str_repeat('あ', 1001)], 'q'],
            'top_k が51' => [[...$base, 'top_k' => '51'], 'top_k'],
            '下限が-1.01' => [[...$base, 'min_similarity' => '-1.01'], 'min_similarity'],
            '下限が1.01' => [[...$base, 'min_similarity' => '1.01'], 'min_similarity'],
            '質問が配列' => [[...$base, 'q' => ['PHP']], 'q'],
            'top_k が配列' => [[...$base, 'top_k' => ['1']], 'top_k'],
            '下限が配列' => [[...$base, 'min_similarity' => ['0.1']], 'min_similarity'],
            '検索対象が配列' => [[...$base, 'scope' => ['job']], 'scope'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_不正な入力は_api_を呼ばずにエラーを出す(array $input, string $field): void
    {
        $response = $this->submit($input)->assertOk();

        $errors = $response->viewData('errors');
        $this->assertTrue($errors->has($field), "{$field} のエラーが出ていない");
        $response->assertSee($errors->first($field));
        $response->assertDontSee('data-testid="search-flow"', false);
        Http::assertNothingSent();
        $this->assertSame(0, QueryLog::count());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function boundaryInputs(): array
    {
        $base = ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'];

        return [
            '質問が1000文字' => [[...$base, 'q' => str_repeat('あ', 1000)]],
            'top_k が1' => [[...$base, 'top_k' => '1']],
            'top_k が50' => [[...$base, 'top_k' => '50']],
            '下限が-1' => [[...$base, 'min_similarity' => '-1']],
            '下限が1' => [[...$base, 'min_similarity' => '1']],
        ];
    }

    #[DataProvider('boundaryInputs')]
    public function test_境界の値は検索できる(array $input): void
    {
        $this->submit($input)
            ->assertOk()
            ->assertSee('data-testid="search-flow"', false);

        $this->assertSame(1, QueryLog::count());
    }

    public function test_質問と文書の中身はエスケープして表示する(): void
    {
        $c3 = Candidate::create(['code' => 'C003', 'name' => '<b>架空</b> 三郎']);
        $this->chunk($this->document($c3, Document::TYPE_CAREER_HISTORY), 0, 0.99, '<i>見出し</i>', '<script>alert(1)</script>本文');
        $this->claudeAnswer = '<script>alert(3)</script> [1]';

        $this->submit(['scope' => 'candidate', 'q' => '<script>alert(2)</script>', 'top_k' => '5', 'min_similarity' => '0', 'score' => 'max'])
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<b>架空</b>', false)
            ->assertDontSee('<i>見出し</i>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;本文', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;alert(3)&lt;/script&gt;', false);
    }
}
