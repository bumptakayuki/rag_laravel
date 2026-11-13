<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesRagApis;
use Tests\TestCase;

/**
 * 質問画面の実験機能（要件定義書 第5章。RS-007）:
 * - 「検索だけ実行」: Claude を呼ばずに検索結果と類似度だけを出す
 * - 「RAGなし比較」: 同じ質問を資料なしで Claude に聞いた回答を並べる（queries.answer_without_rag に記録）
 */
class ExperimentPageTest extends TestCase
{
    use FakesRagApis;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRagApis();

        $c1 = Candidate::create(['code' => 'C001', 'name' => '架空 一郎']);
        $this->chunk($this->document($c1, Document::TYPE_CAREER_HISTORY), 0, 0.82, 'スキル', 'PHP と Laravel で8年開発した');
    }

    private function submit(array $input): TestResponse
    {
        return $this->followingRedirects()->from('/')->post('/', [
            'scope' => 'candidate', 'q' => 'PHPの経験がある人', 'top_k' => 5, 'min_similarity' => 0, 'score' => 'max',
            ...$input,
        ]);
    }

    public function test_フォームに検索だけ実行のボタンと_rag_なし比較のチェックボックスがある(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('name="action" value="ask"', false)
            ->assertSee('name="action" value="search"', false)
            ->assertSee('検索だけ実行（Claude を呼ばない）')
            ->assertSee('name="compare_without_rag"', false)
            // ボタンを押せなくしても押したボタンの値が送られるよう、レイアウトの JavaScript が hidden に写す
            ->assertSee('data-submitter-copy', false);
    }

    public function test_検索だけ実行は_claude_を呼ばずに検索結果と類似度を出し_記録する(): void
    {
        $this->submit(['action' => 'search'])
            ->assertOk()
            ->assertSee('data-testid="search-only"', false)
            ->assertSee('C001')
            ->assertSee('0.8200')
            ->assertDontSee('data-testid="answer"', false);

        $this->assertCount(1, $this->embeddingRequests);
        $this->assertCount(0, $this->claudeRequests);

        $log = QueryLog::sole();
        $this->assertSame(QueryLog::MODE_QUESTION, $log->mode);
        $this->assertNull($log->answer);
        $this->assertNull($log->prompt);
        $this->assertTrue($log->params['search_only']);
        $this->assertArrayNotHasKey('generate', $log->latency_ms);
    }

    public function test_検索だけ実行では_rag_なし比較を付けても_claude_を呼ばない(): void
    {
        $this->submit(['action' => 'search', 'compare_without_rag' => '1'])->assertOk();

        $this->assertCount(0, $this->claudeRequests);
        $this->assertNull(QueryLog::sole()->answer_without_rag);
    }

    public function test_rag_なし比較は資料なしで聞いた回答を記録して並べて表示する(): void
    {
        $this->submit(['action' => 'ask', 'compare_without_rag' => '1'])
            ->assertOk()
            ->assertSee('data-testid="answer"', false)
            ->assertSee('data-testid="answer-without-rag"', false)
            ->assertSee('RAGあり（検索した資料を根拠に回答）')
            ->assertSee('RAGなし（資料を渡さずに質問だけを送った回答）')
            ->assertSee('RAGなし回答（比較）');

        // 1回目 = 資料つき、2回目 = 資料なし（質問だけ）
        $this->assertCount(2, $this->claudeRequests);
        $this->assertStringContainsString('PHP と Laravel で8年開発した', $this->claudeRequests[0]['messages'][0]['content']);
        $this->assertSame('PHPの経験がある人', $this->claudeRequests[1]['messages'][0]['content']);
        $this->assertSame(PromptBuilder::SYSTEM_WITHOUT_RAG, $this->claudeRequests[1]['system']);

        $log = QueryLog::sole();
        $this->assertSame($this->claudeAnswer, $log->answer_without_rag);
        $this->assertTrue($log->params['compare_without_rag']);
        $this->assertArrayHasKey('generate_without_rag', $log->latency_ms);
    }

    public function test_チェックしなければ_rag_なしの回答は作らない(): void
    {
        $this->submit(['action' => 'ask'])
            ->assertOk()
            ->assertSee('data-testid="answer"', false)
            ->assertDontSee('data-testid="answer-without-rag"', false);

        $this->assertCount(1, $this->claudeRequests);
        $log = QueryLog::sole();
        $this->assertNull($log->answer_without_rag);
        $this->assertArrayNotHasKey('compare_without_rag', $log->params);
        $this->assertArrayNotHasKey('search_only', $log->params);
    }

    public function test_action_を送らない古いフォームは回答まで作る(): void
    {
        $this->followingRedirects()->from('/')->post('/', [
            'scope' => 'candidate', 'q' => 'PHPの経験がある人', 'top_k' => 5, 'min_similarity' => 0, 'score' => 'max',
        ])->assertOk()->assertSee('data-testid="answer"', false);

        $this->assertCount(1, $this->claudeRequests);
    }

    public function test_不正な_action_や_compare_は_api_を呼ばずにエラーを出す(): void
    {
        $this->from('/')->post('/', ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => 5, 'min_similarity' => 0, 'score' => 'max', 'action' => 'x'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('action');
        $this->from('/')->post('/', ['scope' => 'candidate', 'q' => 'PHP', 'top_k' => 5, 'min_similarity' => 0, 'score' => 'max', 'compare_without_rag' => 'yes'])
            ->assertSessionHasErrors('compare_without_rag');

        $this->assertCount(0, $this->embeddingRequests);
        $this->assertSame(0, QueryLog::count());
    }

    public function test_履歴の再実行は検索だけ_rag_なし比較の指定も引き継ぐ(): void
    {
        $this->submit(['action' => 'search']);
        $this->submit(['action' => 'ask', 'compare_without_rag' => '1']);
        [$searchOnly, $compared] = QueryLog::orderBy('id')->get()->all();
        $this->claudeRequests = [];

        $this->get('/history')->assertSee('検索のみ')->assertSee('RAGなし比較');

        $this->followingRedirects()->post("/history/{$searchOnly->id}/rerun")->assertOk()->assertSee('data-testid="search-only"', false);
        $this->assertCount(0, $this->claudeRequests);
        $this->assertTrue(QueryLog::latest('id')->first()->params['search_only']);

        $this->followingRedirects()->post("/history/{$compared->id}/rerun")->assertOk()->assertSee('data-testid="answer-without-rag"', false);
        $this->assertCount(2, $this->claudeRequests);
        $this->assertNotNull(QueryLog::latest('id')->first()->answer_without_rag);
    }

    public function test_記録した結果を開くと_フォームに_rag_なし比較の指定が戻る(): void
    {
        $this->submit(['action' => 'ask', 'compare_without_rag' => '1']);

        $this->get('/?query='.QueryLog::sole()->id)
            ->assertOk()
            ->assertSee('name="compare_without_rag" value="1" class="mt-0.5" checked', false);
    }

    public function test_rag_なし回答の生成に失敗したら工程名を出して記録しない(): void
    {
        // 1回目（資料つき）は成功、2回目（資料なし）だけ失敗させる
        $this->claudeFailsFrom = 2;

        $this->submit(['action' => 'ask', 'compare_without_rag' => '1'])
            ->assertOk()
            ->assertSee('RAGなし回答の生成（Claude）');

        $this->assertSame(0, QueryLog::count());
    }
}
