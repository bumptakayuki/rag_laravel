<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\Rag\JobMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 求人票から候補者を探す（POST /jobs/{job}/match）の入力
 */
class JobMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 求人票でない文書は、入力の検証より先に 404 にする（求人票が見つからないのと同じ扱い）
        abort_unless($this->route('job')?->type === Document::TYPE_JOB, 404);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(array_keys(JobMatcher::SOURCE_SECTIONS))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.required' => '検索に使う部分を選んでください。',
            'source.in' => '検索に使う部分の値が正しくありません。',
        ];
    }
}
