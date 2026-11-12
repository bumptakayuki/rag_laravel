<?php

namespace Tests\Unit;

use App\Services\Rag\Contracts\Embedder;
use App\Services\Rag\OpenAiEmbedder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * OpenAiEmbedder のテスト。API は Http::fake() で偽装し、ネットワークは使わない。
 * 再試行の待ち時間は Sleep::fake() で実際には待たない。
 */
class OpenAiEmbedderTest extends TestCase
{
    private const DIMENSIONS = 4;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function embedder(int $batchSize = 50): OpenAiEmbedder
    {
        return new OpenAiEmbedder(
            apiKey: 'test-key',
            model: 'text-embedding-3-small',
            dimensions: self::DIMENSIONS,
            batchSize: $batchSize,
            retryDelaysMs: [1000, 2000, 4000],
            baseUrl: 'https://api.openai.com/v1',
        );
    }

    /** 送られた input の件数ぶん、index 付きのベクトルを返す偽の応答 */
    private static function okResponse(Request $request)
    {
        $data = [];
        foreach ($request['input'] as $i => $text) {
            $data[] = ['index' => $i, 'embedding' => array_fill(0, self::DIMENSIONS, (float) mb_strlen($text))];
        }

        return Http::response(['data' => $data]);
    }

    public function test_container_resolves_openai_embedder(): void
    {
        $this->assertInstanceOf(OpenAiEmbedder::class, app(Embedder::class));
    }

    public function test_sends_texts_in_batches_and_keeps_order(): void
    {
        Http::fake(['api.openai.com/*' => fn (Request $r) => self::okResponse($r)]);
        // 長さの違う 120 件（ベクトルの値 = 文字数なので、順番が保たれているか確かめられる）
        $texts = array_map(fn (int $i) => str_repeat('あ', $i + 1), range(0, 119));

        $vectors = $this->embedder(batchSize: 50)->embed($texts);

        $this->assertCount(120, $vectors);
        foreach ($vectors as $i => $vector) {
            $this->assertSame((float) ($i + 1), $vector[0]);
        }
        // 50 + 50 + 20 の3回に分けて送る
        Http::assertSentCount(3);
        $sizes = Http::recorded()->map(fn ($pair) => count($pair[0]['input']))->all();
        $this->assertSame([50, 50, 20], $sizes);
    }

    public function test_request_contains_model_dimensions_and_api_key(): void
    {
        Http::fake(['api.openai.com/*' => fn (Request $r) => self::okResponse($r)]);

        $this->embedder()->embed(['テスト']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/embeddings'
            && $r->hasHeader('Authorization', 'Bearer test-key')
            && $r['model'] === 'text-embedding-3-small'
            && $r['dimensions'] === self::DIMENSIONS
            && $r['input'] === ['テスト']);
    }

    public function test_reorders_by_index_when_response_is_out_of_order(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['index' => 1, 'embedding' => [2.0, 2.0, 2.0, 2.0]],
            ['index' => 0, 'embedding' => [1.0, 1.0, 1.0, 1.0]],
        ]])]);

        $vectors = $this->embedder()->embed(['a', 'b']);

        $this->assertSame([1.0, 1.0, 1.0, 1.0], $vectors[0]);
        $this->assertSame([2.0, 2.0, 2.0, 2.0], $vectors[1]);
    }

    public function test_retries_rate_limit_with_exponential_backoff(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push(['error' => ['message' => 'server error']], 500)
            ->push(['data' => [['index' => 0, 'embedding' => [1.0, 1.0, 1.0, 1.0]]]]);

        $vectors = $this->embedder()->embed(['a']);

        $this->assertSame([1.0, 1.0, 1.0, 1.0], $vectors[0]);
        Http::assertSentCount(3);
        // 1回目の失敗の後に 1 秒、2回目の後に 2 秒待つ
        Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
    }

    public function test_gives_up_after_three_retries(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        try {
            $this->embedder()->embed(['a']);
            $this->fail('例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
            $this->assertStringContainsString('overloaded', $e->getMessage());
        }

        // 最初の1回 + 再試行3回
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    }

    public function test_does_not_retry_client_errors(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        try {
            $this->embedder()->embed(['a']);
            $this->fail('例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_rejects_wrong_dimensions(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 2.0]]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('次元数');
        $this->embedder()->embed(['a']);
    }

    public function test_rejects_wrong_count(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 1.0, 1.0, 1.0]]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('件数');
        $this->embedder()->embed(['a', 'b']);
    }

    public function test_empty_input_does_not_call_api(): void
    {
        Http::fake();

        $this->assertSame([], $this->embedder()->embed([]));
        Http::assertNothingSent();
    }

    public function test_missing_api_key_is_reported(): void
    {
        $embedder = new OpenAiEmbedder(apiKey: '', model: 'm', dimensions: self::DIMENSIONS);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        $embedder->embed(['a']);
    }
}
