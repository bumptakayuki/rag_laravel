<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 指定した文書がどう分割されたかを一覧表示する（要件定義書 第4.1章）。
 */
class RagChunks extends Command
{
    protected $signature = 'rag:chunks
        {document_id : 文書の ID}
        {--full : 本文を省略せずに表示する}';

    protected $description = '文書のチャンク分割の結果を一覧表示する';

    public function handle(): int
    {
        $document = Document::with(['candidate', 'chunks'])->find($this->argument('document_id'));
        if ($document === null) {
            $this->error("文書が見つからない: id={$this->argument('document_id')}");

            return self::FAILURE;
        }

        $owner = $document->candidate ? "{$document->candidate->code} {$document->candidate->name}" : '（候補者なし）';
        $this->info(sprintf(
            '[%d] %s / %s / %s（%d 文字、チャンク %d 件）',
            $document->id,
            Document::TYPE_LABELS[$document->type] ?? $document->type,
            $owner,
            $document->source_path,
            mb_strlen($document->content),
            $document->chunks->count(),
        ));

        $full = (bool) $this->option('full');
        $this->table(
            ['#', '見出し', '文字数', '埋め込み', '本文'],
            $document->chunks->map(fn ($chunk) => [
                $chunk->chunk_index,
                $chunk->metadata['section'] ?? '',
                $chunk->token_estimate,
                $chunk->embedding === null ? 'なし' : 'あり',
                $full ? $chunk->content : Str::limit(str_replace("\n", ' ', $chunk->content), 60),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
