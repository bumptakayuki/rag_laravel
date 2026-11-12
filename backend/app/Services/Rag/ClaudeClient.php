<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Anthropic Messages API（POST /messages）の呼び出し（要件定義書 第6章）。
 *
 * RS-003 では rag:seed-samples（ダミーデータ生成）で使う、JSON を返させる呼び出しだけを持つ。
 * 回答生成用の呼び出しは RS-005 で足す。
 *
 * - 構造化出力（output_config.format の json_schema）で、決めた形の JSON を必ず返させる
 * - 安全性の判定で断られた（stop_reason = refusal）ときは、サーバー側フォールバック
 *   （fallbacks: "default"）で別モデルが続きを書く。それでも断られたら例外にする
 * - 429・5xx（529 Overloaded を含む）・接続エラーは指数バックオフで再試行する
 */
class ClaudeClient
{
    /** サーバー側フォールバックを使うための beta ヘッダーの値 */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  list<int>  $retryDelaysMs  再試行の待ち時間（ミリ秒）。要素数が再試行回数になる
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $version = '2026-10-21',
        private readonly int $maxTokens = 16000,
        private readonly array $retryDelaysMs = [1000, 2000, 4000],
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
        private readonly int $timeout = 300,
    ) {}

    /**
     * system と user の指示を送り、$schema の形の JSON を連想配列で返す。
     *
     * @param  array<string, mixed>  $schema  JSON Schema（type: object、additionalProperties: false、required あり）
     * @return array<string, mixed>
     */
    public function generateJson(string $system, string $prompt, array $schema): array
    {
        $message = $this->createMessage([
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'fallbacks' => 'default',
        ]);

        $json = json_decode($this->text($message), true);
        if (! is_array($json)) {
            throw new RuntimeException('Claude の応答が JSON として読めなかった');
        }

        return $json;
    }

    /**
     * リクエストを送り、応答（Message オブジェクト）を連想配列で返す。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createMessage(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY が設定されていない（backend/.env に書くこと）');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->version,
                    'anthropic-beta' => self::FALLBACK_BETA,
                ])
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry($this->retryDelaysMs, when: fn (Throwable $e) => self::isRetryable($e))
                ->post('/messages', $payload);
        } catch (RequestException $e) {
            throw new RuntimeException(sprintf(
                'Anthropic Messages API がエラーを返した（HTTP %d）: %s',
                $e->response->status(),
                $e->response->json('error.message') ?? $e->response->body(),
            ), previous: $e);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Anthropic Messages API に接続できなかった: '.$e->getMessage(), previous: $e);
        }

        return $response->json();
    }

    /**
     * 応答からテキストを取り出す。content には thinking ブロックなども混ざるため、type が text のものだけ使う。
     *
     * @param  array<string, mixed>  $message
     */
    private function text(array $message): string
    {
        $stopReason = $message['stop_reason'] ?? null;
        if ($stopReason === 'refusal') {
            throw new RuntimeException('Claude が生成を断った（stop_reason: refusal）: '.($message['stop_details']['explanation'] ?? '理由なし'));
        }
        if ($stopReason === 'max_tokens') {
            throw new RuntimeException('Claude の出力が max_tokens で途中で切れた（ANTHROPIC_MAX_TOKENS を増やすこと）');
        }

        return collect($message['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

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
