<?php

namespace App\Services\Rag;

use RuntimeException;
use Throwable;

/**
 * 外部 API（埋め込み・回答生成）の呼び出しに失敗した。RagPipeline が元の例外を包んで投げる。
 *
 * DB のエラー（QueryException も RuntimeException の仲間）と区別するための型。
 * 画面・コマンドはこれだけを「外部 API の失敗」として扱い、それ以外は原因を取り違えないようそのまま投げる。
 */
class ExternalApiException extends RuntimeException
{
    public function __construct(
        /** 失敗した工程（embed / generate） */
        public readonly string $stage,
        Throwable $previous,
    ) {
        // メッセージは元の例外のまま（ステータスコードと API のエラー文が入っている）
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
