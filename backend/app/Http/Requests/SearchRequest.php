<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\Rag\GroupRanker;
use App\Services\Rag\Retriever;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 質問画面の実行（POST /）の入力。入力の誤りは埋め込み API を呼ぶ前に止める（エラーを付けてフォームへ戻る）
 */
class SearchRequest extends FormRequest
{
    /** フォームの項目 */
    private const FIELDS = ['scope', 'q', 'top_k', 'min_similarity', 'doc_type', 'score', 'action', 'compare_without_rag'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in([Retriever::SCOPE_CANDIDATE, Retriever::SCOPE_JOB])],
            'q' => ['required', 'string', 'max:1000'],
            'top_k' => ['required', 'integer', 'min:1', 'max:50'],
            'min_similarity' => ['required', 'numeric', 'min:-1', 'max:1'],
            'doc_type' => ['nullable', Rule::in([Document::TYPE_RESUME, Document::TYPE_CAREER_HISTORY])],
            'score' => ['required', Rule::in(GroupRanker::SCORE_METHODS)],
            'action' => ['nullable', Rule::in(['ask', 'search'])],
            'compare_without_rag' => ['nullable', 'boolean'],
        ];
    }

    /**
     * lang ファイルを入れていないので、この画面で使う規則のメッセージだけ日本語で書く
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute を入力してください。',
            'string' => ':attribute は文字列で入力してください。',
            'max' => ':attribute は :max 以下にしてください。',
            'min' => ':attribute は :min 以上にしてください。',
            'integer' => ':attribute は整数で入力してください。',
            'numeric' => ':attribute は数値で入力してください。',
            'in' => ':attribute の値が正しくありません。',
            'boolean' => ':attribute の値が正しくありません。',
            'q.max' => ':attribute は :max 文字以内にしてください。',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'scope' => '検索対象', 'q' => '質問', 'top_k' => '表示する件数',
            'min_similarity' => '類似度の下限', 'doc_type' => '検索する文書', 'score' => 'スコアの決め方',
            'action' => '実行の種類', 'compare_without_rag' => 'RAGなし比較',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            // 求人票には文書種別の絞り込みがない（Retriever も受け付けない）
            function (Validator $validator) {
                if ($this->input('scope') === Retriever::SCOPE_JOB && ! empty($this->input('doc_type'))) {
                    $validator->errors()->add('doc_type', '求人票を検索するときは、検索する文書を指定できません。');
                }
            },
        ];
    }

    /**
     * 画面に戻すフォームの値（検証前の入力。配列は '' にしてある）
     *
     * @return array<string, mixed>
     */
    public function formInput(): array
    {
        return $this->only(self::FIELDS) + array_fill_keys(self::FIELDS, null);
    }

    /**
     * ?q[]=x のような配列は '' にする（検証で弾き、画面に出すときのエスケープで落ちないように）
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(self::FIELDS)
            ->filter(fn (string $key) => is_array($this->input($key)))
            ->mapWithKeys(fn (string $key) => [$key => ''])
            ->all());
    }
}
