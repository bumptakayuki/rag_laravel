<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 質問履歴（queries テーブル）。
 *
 * クラス名を Query にすると Eloquent の Model::query() やクエリビルダーと紛らわしいので QueryLog にしている。
 */
class QueryLog extends Model
{
    /** 質問から候補者を探した（rag:ask） */
    public const MODE_QUESTION = 'question';

    /** 質問から求人票を探した（RS-006 の画面で記録する） */
    public const MODE_JOB_SEARCH = 'job_search';

    /** 求人票から候補者を探した（rag:match） */
    public const MODE_JOB_MATCH = 'job_match';

    protected $table = 'queries';

    protected $fillable = ['mode', 'source_document_id', 'question', 'params', 'retrieved', 'prompt', 'answer', 'latency_ms'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'retrieved' => 'array',
            'latency_ms' => 'array',
        ];
    }

    /** 画面に出すモードの名前 */
    public const MODE_LABELS = [
        self::MODE_QUESTION => '回答',
        self::MODE_JOB_SEARCH => '求人票検索',
        self::MODE_JOB_MATCH => '求人→候補者',
    ];

    /**
     * この記録を表示する画面の URL。求人票から探した結果は求人票詳細、それ以外は質問画面。
     * 元の求人票が消えていたら（取り込み直し）質問画面で表示する
     */
    public function resultUrl(): string
    {
        if ($this->mode === self::MODE_JOB_MATCH && $this->source_document_id !== null) {
            return route('jobs.show', ['job' => $this->source_document_id, 'query' => $this->id]);
        }

        return route('search', ['query' => $this->id]);
    }

    /** job_match のときの元の求人票 @return BelongsTo<Document, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}
