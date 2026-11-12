<?php

namespace App\Services\Rag;

/**
 * 1段目（Retriever）で取れたチャンク1件。2段目（GroupRanker）の入力になる。
 *
 * DB の行をそのまま配列で回すと、どのキーがあるかが読み手にわからないため、型をつけて受け渡す。
 */
final readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        /** 求人票のチャンクは null */
        public ?int $candidateId,
        /** 文書種別（Document::TYPE_*） */
        public string $documentType,
        public int $chunkIndex,
        /** 見出し（metadata.section）。見出しより前の本文は null */
        public ?string $section,
        public string $content,
        /** コサイン類似度 = 1 − コサイン距離（<=>）。1 に近いほど質問と向きが近い */
        public float $similarity,
    ) {}
}
