<?php

namespace App\Services\Rag;

/**
 * プロンプトに入れた資料1件（[番号] とチャンクの対応）。
 */
final readonly class PromptSource
{
    public function __construct(
        /** 資料番号（1 始まり） */
        public int $number,
        public int $candidateId,
        public RetrievedChunk $chunk,
    ) {}
}
