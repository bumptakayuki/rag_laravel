<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 候補者（架空データ）。履歴書・職務経歴書を各1件まで持つ。
 */
class Candidate extends Model
{
    protected $fillable = ['code', 'name'];

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
