<?php

namespace App\Services\Rag;

use App\Services\Rag\Contracts\Embedder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OpenAI Embeddings API（POST /embeddings）で埋め込みを作る。
 *
 * - テキストは batchSize 件ずつまとめて1回の API 呼び出しで送る（呼び出し回数を減らす）
 * - 429（レート制限）・5xx・接続エラーのときは、retryDelaysMs の待ち時間で再試行する（指数バックオフ）
 * - 返ってきたベクトルの件数・次元数が想定と違えば例外にする（壊れたデータを DB に入れない）
 */
class OpenAiEmbedder implements Embedder
{
    /**
     * @param  list<int>  $retryDelaysMs  再試行の待ち時間（ミリ秒）。要素数が再試行回数になる
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $batchSize = 50,
        private readonly array $retryDelaysMs = [1000, 2000, 4000],
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $timeout = 30,
    ) {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException("batch_size は1以上にすること（指定: {$batchSize}）");
        }
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY が設定されていない（backend/.env に書くこと）');
        }

        $vectors = [];
        foreach (array_chunk(array_values($texts), $this->batchSize) as $batch) {
            array_push($vectors, ...$this->embedBatch($batch));
        }

        return $vectors;
    }

    /**
     * 1回分（batchSize 件以下）を API に送る。
     *
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(array $batch): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                // 待ち時間の配列を渡すと、その回数だけ再試行する。$when で「再試行してよい失敗」だけに絞る
                ->retry($this->retryDelaysMs, when: fn (Throwable $e) => self::isRetryable($e))
                ->post('/embeddings', [
                    'model' => $this->model,
                    'input' => $batch,
                    // text-embedding-3 系は次元数を指定できる。chunks.embedding の vector(次元数) と必ず揃える
                    'dimensions' => $this->dimensions,
                ]);
        } catch (RequestException $e) {
            // 再試行しても失敗した、または再試行しない失敗（400 / 401 など）。ステータスとメッセージを出す
            throw new RuntimeException(sprintf(
                'OpenAI Embeddings API がエラーを返した（HTTP %d）: %s',
                $e->response->status(),
                $e->response->json('error.message') ?? $e->response->body(),
            ), previous: $e);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenAI Embeddings API に接続できなかった: '.$e->getMessage(), previous: $e);
        }

        // data[] は index（送った順番）を持つ。念のため index で並べ直してから取り出す
        $data = collect($response->json('data', []))->sortBy('index')->values();

        if ($data->count() !== count($batch)) {
            throw new RuntimeException(sprintf('埋め込みの件数が合わない（送信 %d 件、受信 %d 件）', count($batch), $data->count()));
        }

        return $data->map(function (array $item): array {
            $vector = $item['embedding'] ?? [];
            if (count($vector) !== $this->dimensions) {
                throw new RuntimeException(sprintf('埋め込みの次元数が設定と違う（設定 %d、受信 %d）', $this->dimensions, count($vector)));
            }

            return array_map('floatval', $vector);
        })->all();
    }

    /** 時間をおけば成功しうる失敗か（レート制限・サーバー側の一時的なエラー・接続エラー） */
    private static function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }
        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
