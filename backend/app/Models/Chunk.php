<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 検索対象の断片。embedding は pgvector の vector 型。
 *
 * AsVector キャスト（Laravel 本体）で、PHP の float 配列 ⇔ pgvector の '[0.1,0.2,...]' を相互変換する。
 * ベクトル検索そのものは Eloquent ではなく生SQL（Retriever: RS-004）で行う。
 */
class Chunk extends Model
{
    protected $fillable = ['document_id', 'chunk_index', 'content', 'token_estimate', 'metadata', 'embedding'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
