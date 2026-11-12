<?php

namespace Tests\Feature;

use App\Services\Rag\ClaudeClient;
use App\Services\Rag\Ingestor;
use App\Services\Rag\Samples\SampleGenerator;
use App\Services\Rag\Samples\SampleRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * ダミーデータ生成（ClaudeClient・SampleGenerator・SampleRoster）のテスト。
 * Messages API は Http::fake() で偽装し、ネットワークは使わない。出力先は一時ディレクトリ。
 */
class SampleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->dir = sys_get_temp_dir().'/rag-samples-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Messages API の応答（text ブロックに JSON を入れる。thinking ブロックも混ぜる） */
    private static function messageResponse(array $json, string $stopReason = 'end_turn')
    {
        return Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)],
            ],
            'stop_reason' => $stopReason,
        ]);
    }

    /** スキーマの required のキーすべてに、問題のない本文を入れて返す偽の Claude */
    private function fakeClaude(?callable $override = null): void
    {
        Http::fake(['api.anthropic.com/*' => function (Request $request) use ($override) {
            $keys = $request['output_config']['format']['schema']['required'];
            $json = [];
            foreach ($keys as $key) {
                $json[$key] = $key === 'company'
                    ? '株式会社サンプル商事'
                    : str_repeat("{$key} の本文です。サンプル大学を卒業し、株式会社サンプルで働いた。", 8)."\n\n- 箇条書き";
            }

            return self::messageResponse($override ? $override($json, $request) : $json);
        }]);
    }

    private function generate(bool $force = false): array
    {
        return app(SampleGenerator::class)->generate($this->dir, "{$this->dir}/eval.json", $force);
    }

    public function test_generates_files_in_ingest_layout(): void
    {
        $this->fakeClaude();

        $result = $this->generate();

        $this->assertCount(30, $result['generated']);
        // 候補者1名につき1回、求人票1件につき1回
        Http::assertSentCount(30);
        foreach (SampleRoster::candidates() as $candidate) {
            $resume = File::get("{$this->dir}/candidates/{$candidate['code']}/rirekisho.md");
            $career = File::get("{$this->dir}/candidates/{$candidate['code']}/shokumu.md");
            // Ingestor が氏名を取れる形になっている
            $this->assertSame($candidate['name'], Ingestor::extractName($resume));
            foreach (SampleGenerator::RESUME_SECTIONS as $heading) {
                $this->assertStringContainsString("\n## {$heading}\n", $resume);
            }
            foreach (SampleGenerator::CAREER_SECTIONS as $heading) {
                $this->assertStringContainsString("\n## {$heading}\n", $career);
            }
        }
        $this->assertCount(10, File::glob("{$this->dir}/jobs/*.md"));
        $this->assertStringContainsString('会社名：株式会社サンプル商事', File::get("{$this->dir}/jobs/J01-backend-php.md"));
    }

    public function test_writes_eval_json_from_roster(): void
    {
        $this->fakeClaude();
        $this->generate();

        $eval = json_decode(File::get("{$this->dir}/eval.json"), true);

        $this->assertCount(10, $eval['questions']);
        $codes = array_column(SampleRoster::candidates(), 'code');
        foreach ($eval['questions'] as $question) {
            $this->assertNotEmpty($question['expected']);
            $this->assertSame([], array_diff($question['expected'], $codes), $question['question']);
        }
    }

    public function test_existing_files_are_not_regenerated_without_force(): void
    {
        $this->fakeClaude();
        $this->generate();
        $before = File::get("{$this->dir}/candidates/C001/rirekisho.md");

        $result = $this->generate();

        $this->assertSame([], $result['generated']);
        $this->assertCount(30, $result['skipped']);
        Http::assertSentCount(30); // 2回目は API を呼んでいない
        $this->assertSame($before, File::get("{$this->dir}/candidates/C001/rirekisho.md"));

        $this->assertCount(30, $this->generate(force: true)['generated']);
        Http::assertSentCount(60);
    }

    public function test_request_uses_structured_output_fallbacks_and_headers(): void
    {
        $this->fakeClaude();
        $this->generate();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->hasHeader('x-api-key', 'test-anthropic-key')
            && $r->hasHeader('anthropic-version', config('rag.anthropic.version'))
            && $r->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $r['model'] === config('rag.anthropic.model')
            && $r['fallbacks'] === 'default'
            && $r['output_config']['format']['type'] === 'json_schema'
            && $r['output_config']['format']['schema']['additionalProperties'] === false);
    }

    public function test_forbidden_words_are_sent_and_violations_are_retried(): void
    {
        // C002 は「Laravel」を書かない候補者（言い換え）。1回目だけ禁止語を含めて返す
        $calls = 0;
        $this->fakeClaude(function (array $json, Request $request) use (&$calls) {
            // 「PHP の MVC フレームワーク」は C002 の事実にだけある文言
            if (str_contains($request['messages'][0]['content'], 'PHP の MVC フレームワーク') && ++$calls === 1) {
                $json['skills'] = 'Laravel を6年使いました。';
            }

            return $json;
        });

        $this->generate();

        $c002 = Http::recorded()
            ->map(fn ($pair) => $pair[0]['messages'][0]['content'])
            ->filter(fn ($content) => str_contains($content, 'PHP の MVC フレームワーク'))
            ->values();
        // 禁止語をプロンプトで伝えている
        $this->assertStringContainsString('次の語は使わないでください', $c002[0]);
        $this->assertStringContainsString('Laravel', $c002[0]);
        // 違反したので、理由を添えて作り直させた
        $this->assertCount(2, $c002);
        $this->assertStringContainsString('使ってはいけない語「Laravel」がある', $c002[1]);
        $this->assertStringNotContainsString('Laravel', File::get("{$this->dir}/candidates/C002/shokumu.md"));
    }

    public function test_gives_up_after_three_invalid_outputs(): void
    {
        $this->fakeClaude(fn (array $json) => ['education' => '```code```'] + $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3 回とも検査に通らなかった');
        $this->generate();
    }

    public function test_refusal_is_reported(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => 'declined'],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusal');
        app(ClaudeClient::class)->generateJson('s', 'p', ['type' => 'object']);
    }

    public function test_api_error_is_reported_with_status(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad schema']], 400)]);

        try {
            app(ClaudeClient::class)->generateJson('s', 'p', ['type' => 'object']);
            $this->fail('例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('bad schema', $e->getMessage());
        }
        Http::assertSentCount(1); // 400 は再試行しない
    }

    public function test_overloaded_is_retried(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push(['content' => [['type' => 'text', 'text' => '{"a":"b"}']], 'stop_reason' => 'end_turn']);

        $this->assertSame(['a' => 'b'], app(ClaudeClient::class)->generateJson('s', 'p', ['type' => 'object']));
        Http::assertSentCount(2);
    }

    public function test_problems_detects_personal_data_and_markdown(): void
    {
        $keys = ['a'];
        $check = fn (string $text, array $forbidden = []) => SampleGenerator::problems(['a' => $text], $keys, $forbidden);

        $this->assertSame([], $check("2018年4月〜2022年3月、2018-2022 年に開発。\n\n- PHP"));
        $this->assertContains('メールアドレスらしき文字列がある', $check('連絡先 taro@example.com'));
        $this->assertContains('電話番号らしき文字列がある', $check('電話 03-1234-5678'));
        $this->assertContains('郵便番号らしき文字列がある', $check('〒100-0001'));
        $this->assertContains('URL がある', $check('https://example.com'));
        $this->assertContains('生年月日・住所・年齢の記載がある', $check('生年月日: 2000年1月1日'));
        $this->assertContains('コードブロック（```）がある', $check("```\n# x\n```"));
        $this->assertContains('Markdown の見出し（#）がある', $check("## 小見出し\n本文"));
        $this->assertContains('a が空', SampleGenerator::problems(['a' => ' '], $keys, []));
        // 英数字の禁止語は単語として探す（EKS が weeks に一致しない）。大文字小文字は区別しない
        $this->assertSame([], $check('two weeks', ['EKS']));
        $this->assertContains('使ってはいけない語「Laravel」がある', $check('laravel を使った', ['Laravel']));
        $this->assertContains('使ってはいけない語「機械学習」がある', $check('機械学習を学んだ', ['機械学習']));
    }

    public function test_roster_matches_requirements(): void
    {
        $candidates = SampleRoster::candidates();
        $codes = array_column($candidates, 'code');

        $this->assertCount(20, $candidates);
        $this->assertSame($codes, array_unique($codes));
        $this->assertCount(10, SampleRoster::jobs());
        $this->assertCount(10, SampleRoster::evalQuestions());
        $years = array_column($candidates, 'years');
        $this->assertSame(1, min($years));
        $this->assertSame(15, max($years));
        // 言い換えの候補者には製品名を書かせない
        $this->assertContains('Laravel', SampleRoster::forbiddenWordsFor('C002'));
        $this->assertContains('Kubernetes', SampleRoster::forbiddenWordsFor('C013'));
        $this->assertNotContains('Laravel', SampleRoster::forbiddenWordsFor('C001'));
    }
}
