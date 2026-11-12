<?php

namespace App\Services\Rag;

/**
 * 取り込み1回分の結果（rag:ingest が表示する）。
 */
final class IngestReport
{
    /**
     * @param  list<string>  $ingested  取り込んだ文書（source_path）
     * @param  list<string>  $skipped  スキップした文書と理由（"candidates/C001/rirekisho.md: 変更なし" の形）
     * @param  list<string>  $warnings  取り込めなかったものの説明（ファイルが無いなど）
     */
    public function __construct(
        public readonly array $ingested,
        public readonly array $skipped,
        public readonly array $warnings,
        public readonly int $chunkCount,
        public readonly float $averageChars,
        public readonly int $elapsedMs,
        public readonly int $chunkSize,
        public readonly int $chunkOverlap,
    ) {}
}
