<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 質問履歴（queries テーブル）。
 *
 * クラス名を Query にすると Eloquent の Model::query() やクエリビルダーと紛らわしいので QueryLog にしている。
 */
class QueryLog extends Model
{
    protected $table = 'queries';

    protected $fillable = ['question', 'params', 'retrieved', 'prompt', 'answer', 'latency_ms'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'retrieved' => 'array',
            'latency_ms' => 'array',
        ];
    }
}
