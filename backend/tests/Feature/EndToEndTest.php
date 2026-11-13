<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 取り込み〜回答までの結合テスト（要件定義書 第7章。RS-007）。
 *
 * Markdown のファイルを置いたディレクトリから、実際のコマンドを順に実行する:
 *   rag:ingest（分割 → 埋め込み → 保存）→ rag:search（検索）→ rag:ask（検索 → プロンプト → 回答 → 記録）→ 画面で表示
 *
 * 外部 API は Http::fake() で偽装する（ネットワークは使わない）。埋め込みは「PHP / Laravel を含む文は軸 0、
 * それ以外は軸 1」の単位ベクトルを返す。質問「PHPの経験がある人は？」は軸 0 なので、
 * PHP を書いた候補者のチャンクだけが類似度 1、それ以外は 0 になり、結果を決め打ちで確かめられる。
 */
class EndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var list<Request> */
    private array $claudeRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/rag-e2e-test-'.uniqid();
        $this->writeSamples();
        $this->fakeApis();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fakeApis(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions');
        $axis = function (int $index) use ($dimensions): array {
            $vector = array_fill(0, $dimensions, 0.0);
            $vector[$index] = 1.0;

            return $vector;
        };

        Http::fake([
            'api.openai.com/*' => fn (Request $request) => Http::response(['data' => array_map(
                fn (int $i, string $text) => ['index' => $i, 'embedding' => $axis(preg_match('/PHP|Laravel/u', $text) ? 0 : 1)],
                array_keys($request['input']),
                $request['input'],
            )]),
            'api.anthropic.com/*' => function (Request $request) {
                $this->claudeRequests[] = $request;

                return Http::response([
                    'id' => 'msg_e2e',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'C001 架空 一郎は PHP と Laravel で8年開発しています [1]。']],
                    'stop_reason' => 'end_turn',
                ]);
            },
        ]);
    }

    private function writeSamples(): void
    {
        $people = [
            'C001' => ['架空 一郎', "## スキル\n\n- PHP（8年）\n- Laravel（6年）\n\n## 職務経歴\n\nPHP と Laravel で業務システムを8年開発した。"],
            'C002' => ['架空 二郎', "## スキル\n\n- Go\n- Kubernetes\n\n## 職務経歴\n\nコンテナ基盤の運用を5年担当した。"],
            'C003' => ['架空 三郎', "## スキル\n\n- 採用面接\n- 研修の企画\n\n## 職務経歴\n\n人事として新卒採用を担当した。"],
        ];
        foreach ($people as $code => [$name, $body]) {
            $this->writeFile("candidates/{$code}/rirekisho.md", "# 履歴書\n\n氏名：{$name}\n\n## 学歴\n\nサンプル大学を卒業。");
            $this->writeFile("candidates/{$code}/shokumu.md", "# 職務経歴書\n\n氏名：{$name}\n\n{$body}");
        }
        $this->writeFile('jobs/J01.md', "# 求人票：バックエンドエンジニア\n\n募集職種：バックエンドエンジニア\n\n## 必須条件\n\n- PHP の実務経験3年以上");
    }

    private function writeFile(string $relative, string $content): void
    {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$relative}"));
        File::put("{$this->dir}/{$relative}", $content);
    }

    public function test_取り込みから回答と記録まで通る(): void
    {
        // 1. 取り込み: 候補者3名（6文書）と求人票1件。すべてのチャンクにベクトルが入る
        $this->artisan('rag:ingest', ['path' => $this->dir])->assertSuccessful();

        $this->assertSame(3, Candidate::count());
        $this->assertSame(7, Document::count());
        $this->assertGreaterThan(0, Chunk::count());
        $this->assertSame(0, Chunk::whereNull('embedding')->count());

        // 2. 検索だけ（Claude は呼ばない）: PHP を書いた C001 が1位
        $this->artisan('rag:search', ['question' => 'PHPの経験がある人は？'])
            ->expectsOutputToContain('1. C001 架空 一郎  スコア 1.0000')
            ->assertSuccessful();
        $this->assertSame([], $this->claudeRequests);

        // 3. 回答: C001 の PHP のチャンクが資料 [1] としてプロンプトに入り、回答と記録が残る
        $this->artisan('rag:ask', ['question' => 'PHPの経験がある人は？'])
            ->expectsOutputToContain('C001 架空 一郎は PHP と Laravel で8年開発しています [1]。')
            ->expectsOutputToContain('queries に記録した')
            ->assertSuccessful();

        $this->assertCount(1, $this->claudeRequests);
        $user = $this->claudeRequests[0]['messages'][0]['content'];
        $this->assertStringContainsString('## 候補者 C001 架空 一郎', $user);
        $this->assertMatchesRegularExpression('/\[1\] \(職務経歴書・[^)]+\) [^\n]*PHP/u', $user);
        $this->assertStringContainsString('PHPの経験がある人は？', $user);

        $log = QueryLog::sole();
        $this->assertSame(QueryLog::MODE_QUESTION, $log->mode);
        $c001 = Candidate::where('code', 'C001')->sole();
        $this->assertSame($c001->id, $log->retrieved['candidates'][0]['candidate_id']);
        $this->assertSame($c001->id, $log->retrieved['sources'][0]['candidate_id']);
        $source = Chunk::findOrFail($log->retrieved['sources'][0]['chunk_id']);
        $this->assertStringContainsString('PHP', $source->content);

        // 4. 画面: 記録した結果を開くと、回答と候補者が出る（API はもう呼ばない）
        $this->get('/?query='.$log->id)
            ->assertOk()
            ->assertSee('C001 架空 一郎は PHP と Laravel で8年開発しています [1]。')
            ->assertSee('架空 一郎');
        $this->assertCount(1, $this->claudeRequests);
    }
}
