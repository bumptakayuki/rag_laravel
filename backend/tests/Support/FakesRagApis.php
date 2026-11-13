<?php

namespace Tests\Support;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * 画面のテスト（RS-006）で共通に使う、外部 API の偽装とデータの作り方。
 *
 * - 埋め込み（OpenAI）は、どの文も軸 0 の単位ベクトルを返す。チャンクは cosθ·軸0 + sinθ·軸1 にして、
 *   質問との類似度をちょうど cosθ にする（SearchTest・AskTest と同じ作り方）
 * - 回答生成（Anthropic）は $claudeAnswer を返す。送られたリクエストは $claudeRequests に残す
 * - $embeddingFails / $claudeFails を true にすると 400 を返す（再試行しないエラー）。$claudeFailsFrom = n なら n 回目以降の Claude だけ
 */
trait FakesRagApis
{
    /** @var list<Request> */
    protected array $claudeRequests = [];

    /** @var list<Request> */
    protected array $embeddingRequests = [];

    protected string $claudeAnswer = 'C001 架空 一郎は PHP と Laravel で8年開発しています [1]。';

    protected bool $embeddingFails = false;

    protected bool $claudeFails = false;

    /** n 回目（1始まり）以降の Claude 呼び出しだけ失敗させる（RAGなし比較の2回目だけを失敗させるテスト用） */
    protected ?int $claudeFailsFrom = null;

    protected function fakeRagApis(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions');

        Http::fake([
            'api.openai.com/*' => function (Request $request) use ($dimensions) {
                $this->embeddingRequests[] = $request;

                return $this->embeddingFails
                    ? Http::response(['error' => ['message' => 'invalid model']], 400)
                    : Http::response(['data' => array_map(
                        fn ($i) => ['index' => $i, 'embedding' => $this->vector(1.0, $dimensions)],
                        array_keys($request['input']),
                    )]);
            },
            'api.anthropic.com/*' => function (Request $request) {
                $this->claudeRequests[] = $request;

                $fails = $this->claudeFails || ($this->claudeFailsFrom !== null && count($this->claudeRequests) >= $this->claudeFailsFrom);

                return $fails
                    ? Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad request']], 400)
                    : Http::response([
                        'id' => 'msg_test',
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => $this->claudeAnswer]],
                        'stop_reason' => 'end_turn',
                    ]);
            },
        ]);
    }

    /** 軸 0 との類似度が $similarity になる単位ベクトル */
    protected function vector(float $similarity, ?int $dimensions = null): array
    {
        $vector = array_fill(0, $dimensions ?? (int) config('rag.embedding.dimensions'), 0.0);
        $vector[0] = $similarity;
        $vector[1] = sqrt(max(0.0, 1 - $similarity ** 2));

        return $vector;
    }

    protected function document(?Candidate $candidate, string $type, string $content = '本文', ?string $title = null, ?string $sourcePath = null): Document
    {
        return Document::create([
            'candidate_id' => $candidate?->id,
            'type' => $type,
            'title' => $title ?? $type,
            'source_path' => $sourcePath ?? uniqid('test/').'.md',
            'content' => $content,
            'content_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    protected function chunk(Document $document, int $index, float $similarity, ?string $section, string $content, int $overlap = 0): Chunk
    {
        return Chunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'content' => $content,
            'token_estimate' => mb_strlen($content),
            'metadata' => ['type' => $document->type, 'section' => $section, 'chunk_overlap' => $overlap],
            'embedding' => $this->vector($similarity),
        ]);
    }
}
