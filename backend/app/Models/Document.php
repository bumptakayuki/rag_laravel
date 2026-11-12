<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 取り込んだ文書。履歴書・職務経歴書は候補者に属し、求人票は candidate_id が NULL。
 */
class Document extends Model
{
    // 定数名は英語、値は要件定義書の type・取り込み元のファイル名（rirekisho.md / shokumu.md）に合わせる

    /** 履歴書 */
    public const TYPE_RESUME = 'rirekisho';

    /** 職務経歴書 */
    public const TYPE_CAREER_HISTORY = 'shokumu';

    /** 求人票 */
    public const TYPE_JOB = 'job';

    /** 画面表示用の種別名 */
    public const TYPE_LABELS = [
        self::TYPE_RESUME => '履歴書',
        self::TYPE_CAREER_HISTORY => '職務経歴書',
        self::TYPE_JOB => '求人票',
    ];

    protected $fillable = ['candidate_id', 'type', 'title', 'source_path', 'content', 'content_hash'];

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class)->orderBy('chunk_index');
    }
}
