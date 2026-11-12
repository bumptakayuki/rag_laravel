<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 簡易検索画面（/）のテスト（RS-004b）。
 *
 * 質問ベクトルは軸 0 の単位ベクトル、チャンクは cosθ·軸0 + sinθ·軸1 にして、類似度をちょうど cosθ にする
 * （SearchTest と同じ作り方）。埋め込み API は Http::fake() で偽装する。
 */
class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    private Document $job;

    protected function setUp(): void
    {
        parent::setUp();

        $dimensions = (int) config('rag.embedding.dimensions');
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['index' => 0, 'embedding' => $this->vector(1.0, $dimensions)],
        ]])]);

        $c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $c2 = Candidate::create(['code' => 'C002', 'name' => '架空 二郎']);

        $shokumu = $this->document($c1, Document::TYPE_CAREER_HISTORY);
        $this->chunk($shokumu, 0, 0.82, 'スキル', 'PHP と Laravel で8年開発した');
        $this->chunk($shokumu, 1, 0.40, '自己PR', 'チームで設計を進めた');
        $this->chunk($this->document($c1, Document::TYPE_RESUME), 3, 0.55, '志望動機', 'PHP を軸にキャリアを積みたい');
        $this->chunk($this->document($c2, Document::TYPE_CAREER_HISTORY), 0, 0.74, 'スキル', 'PHP のフレームワークで EC サイトを開発');

        $this->job = $this->document(null, Document::TYPE_JOB, 'バックエンドエンジニア（PHP / Laravel）');
        $this->chunk($this->job, 4, 0.95, '必須条件', 'PHP と Laravel の実務経験3年以上');
        $this->chunk($this->job, 6, 0.60, '歓迎条件', '自動テストの経験');
    }

    /** 軸 0 との類似度が $similarity になる単位ベクトル */
    private function vector(float $similarity, ?int $dimensions = null): array
    {
        $vector = array_fill(0, $dimensions ?? (int) config('rag.embedding.dimensions'), 0.0);
        $vector[0] = $similarity;
        $vector[1] = sqrt(1 - $similarity ** 2);

        return $vector;
    }

    private function document(?Candidate $candidate, string $type, ?string $title = null): Document
    {
        return Document::create([
            'candidate_id' => $candidate?->id,
            'type' => $type,
            'title' => $title ?? $type,
            'source_path' => uniqid('test/').'.md',
            'content' => '本文',
            'content_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function chunk(Document $document, int $index, float $similarity, string $section, string $content): void
    {
        Chunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'content' => $content,
            'token_estimate' => mb_strlen($content),
            'metadata' => ['type' => $document->type, 'section' => $section],
            'embedding' => $this->vector($similarity),
        ]);
    }

    public function test_最初の表示はフォームだけで_api_を呼ばない(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('検索する')
            ->assertSee('質問')
            ->assertSee('表示する件数')
            ->assertSee('類似度の下限')
            ->assertSee('検索する文書（候補者のみ）')
            ->assertSee('スコアの決め方')
            ->assertDontSee('data-testid="search-flow"', false);

        Http::assertNothingSent();
    }

    public function test_候補者を検索すると1人1カードでスコア_バッジ_抜粋が並ぶ(): void
    {
        $response = $this->get('/?'.http_build_query(['q' => 'PHPの経験がある人']))->assertOk();

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
        Http::assertSentCount(1);
    }

    public function test_検索の流れに件数_所要時間_実行sqlが出る(): void
    {
        $this->get('/?'.http_build_query(['q' => 'PHP', 'min_similarity' => '0.5']))
            ->assertOk()
            ->assertSee('data-testid="search-flow"', false)
            ->assertSee('質問を 1536 個の数値に')
            ->assertSee('近い順に 4 件取得')
            ->assertSee('3 文書・4 チャンクが対象')
            ->assertSee('3 件 → 2 名 → 上位 2 名')
            ->assertSee('下限 0.5 未満の 1 件を除外')
            ->assertSee(' ms')
            ->assertSee('ORDER BY c.embedding &lt;=&gt; :q', false)
            ->assertSee('LIMIT :limit')
            ->assertSee(':limit = 50');
    }

    public function test_文書種別_top_k_スコア方法を指定できる(): void
    {
        $response = $this->get('/?'.http_build_query([
            'q' => 'PHP', 'doc_type' => 'rirekisho', 'top_k' => '1', 'score' => 'max_plus_hits',
        ]))->assertOk();

        $response->assertSee('候補者ランキング（上位 1 名 / 該当 1 名）');
        $response->assertSee('最大類似度 ＋ 2件目以降のヒット1件につき', false);
        $response->assertSee('d.type = :type');
        $response->assertSee(':type = rirekisho');
        $response->assertSeeInOrder(['架空 一郎', '0.5500']);
        $response->assertDontSee('架空 二郎');
        // 履歴書だけに絞ったので、展開部分に職務経歴書の欄は出ない
        $response->assertDontSee('職務経歴書 ');
    }

    public function test_求人票を検索すると1求人1カードで職種名と見出しごとの類似度が出る(): void
    {
        $response = $this->get('/?'.http_build_query(['q' => 'PHPの経験がある人', 'scope' => 'job']))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="job-card"'));
        $response->assertSee('求人票ランキング（上位 1 件 / 該当 1 件）');
        $response->assertSeeInOrder([
            '#1', 'バックエンドエンジニア（PHP / Laravel）', "文書 ID {$this->job->id}", 'ヒット 2件',
            '必須条件', '0.9500', '歓迎条件', '0.6000',
            'PHP と Laravel の実務経験3年以上', '0.9500',
        ]);
        $response->assertDontSee('架空 一郎');
    }

    public function test_下限以上のヒットがなければその旨を出す(): void
    {
        $this->get('/?'.http_build_query(['q' => 'PHP', 'min_similarity' => '0.99']))
            ->assertOk()
            ->assertSee('下限以上のヒットがありません');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidInputs(): array
    {
        return [
            '質問が空' => [['q' => ''], 'q'],
            '検索対象が不正' => [['q' => 'PHP', 'scope' => 'person'], 'scope'],
            'top_k が0' => [['q' => 'PHP', 'top_k' => '0'], 'top_k'],
            'top_k が小数' => [['q' => 'PHP', 'top_k' => '1.5'], 'top_k'],
            '下限が範囲外' => [['q' => 'PHP', 'min_similarity' => '2'], 'min_similarity'],
            '下限が数値でない' => [['q' => 'PHP', 'min_similarity' => 'high'], 'min_similarity'],
            '文書種別が不正' => [['q' => 'PHP', 'doc_type' => 'job'], 'doc_type'],
            'スコア方法が不正' => [['q' => 'PHP', 'score' => 'sum'], 'score'],
            '求人票で文書種別を指定' => [['q' => 'PHP', 'scope' => 'job', 'doc_type' => 'shokumu'], 'doc_type'],
            '質問が空白だけ' => [['q' => "  \n "], 'q'],
            '質問が1001文字' => [['q' => str_repeat('あ', 1001)], 'q'],
            'top_k が51' => [['q' => 'PHP', 'top_k' => '51'], 'top_k'],
            '下限が-1.01' => [['q' => 'PHP', 'min_similarity' => '-1.01'], 'min_similarity'],
            '下限が1.01' => [['q' => 'PHP', 'min_similarity' => '1.01'], 'min_similarity'],
            '質問が配列' => [['q' => ['PHP']], 'q'],
            'top_k が配列' => [['q' => 'PHP', 'top_k' => ['1']], 'top_k'],
            '下限が配列' => [['q' => 'PHP', 'min_similarity' => ['0.1']], 'min_similarity'],
            '検索対象が配列' => [['q' => 'PHP', 'scope' => ['job']], 'scope'],
        ];
    }

    /** @return array<string, array{array<string, string>}> */
    public static function boundaryInputs(): array
    {
        return [
            '質問が1000文字' => [['q' => str_repeat('あ', 1000)]],
            'top_k が1' => [['q' => 'PHP', 'top_k' => '1']],
            'top_k が50' => [['q' => 'PHP', 'top_k' => '50']],
            '下限が-1' => [['q' => 'PHP', 'min_similarity' => '-1']],
            '下限が1' => [['q' => 'PHP', 'min_similarity' => '1']],
        ];
    }

    #[DataProvider('boundaryInputs')]
    public function test_境界の値は検索できる(array $query): void
    {
        $this->get('/?'.http_build_query($query))
            ->assertOk()
            ->assertSee('data-testid="search-flow"', false);

        Http::assertSentCount(1);
    }

    public function test_質問と文書の中身はエスケープして表示する(): void
    {
        $c3 = Candidate::create(['code' => 'C003', 'name' => '<b>架空</b> 三郎']);
        $this->chunk($this->document($c3, Document::TYPE_CAREER_HISTORY), 0, 0.99, '<i>見出し</i>', '<script>alert(1)</script>本文');

        $this->get('/?'.http_build_query(['q' => '<script>alert(2)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<b>架空</b>', false)
            ->assertDontSee('<i>見出し</i>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;本文', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
    }

    #[DataProvider('invalidInputs')]
    public function test_不正な入力は_api_を呼ばずにエラーを出す(array $query, string $field): void
    {
        $response = $this->get('/?'.http_build_query($query))->assertOk();

        $errors = $response->viewData('errors');
        $this->assertTrue($errors->has($field), "{$field} のエラーが出ていない");
        $response->assertSee($errors->first($field));
        $response->assertDontSee('data-testid="search-flow"', false);
        Http::assertNothingSent();
    }
}
