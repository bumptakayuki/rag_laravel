<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RunsRagPipeline;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\GroupRanker;
use App\Services\Rag\QueryLogRestorer;
use App\Services\Rag\RagPipeline;
use App\Services\Rag\RagResult;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use Illuminate\View\View;

/**
 * 質問画面（/。要件定義書 第4.4章）。
 *
 *   GET  /              フォームだけ出す（API は呼ばない）
 *   POST /              RagPipeline で実行し、queries に記録して GET /?query={id} へリダイレクトする
 *                       候補者モード = 検索 → 回答生成（ask）、求人票モード = 求人票の検索だけ（searchJobs）
 *   GET  /?query={id}   記録1件を表示する（実行直後・履歴の詳細）。フォームにはその時の条件を入れる
 *
 * 実験機能（要件定義書 第5章。RS-007）:
 * - 「検索だけ実行」ボタン（action=search）: Claude を呼ばずに検索結果と類似度だけを出す
 * - 「RAGなし比較」チェックボックス（compare_without_rag）: 同じ質問を資料なしで Claude に聞いた回答を並べる
 *
 * RS-004b では GET /?q=... で検索していたが、回答生成（有料の API）と記録が入ったので POST にした。
 */
class SearchController extends Controller
{
    use RunsRagPipeline;

    public function index(Request $request, QueryLogRestorer $restorer, Retriever $retriever): View
    {
        $log = null;
        if ($request->filled('query')) {
            $id = $request->query('query');
            abort_unless(is_string($id) && ctype_digit($id), 404);
            $log = QueryLog::findOrFail((int) $id);
        }

        $page = ['form' => $this->form($log), 'result' => null];
        if ($log === null) {
            return view('search', $page);
        }

        return view('search', [...$page, ...$this->resultViewData($restorer->restore($log), $retriever)]);
    }

    public function run(Request $request, RagPipeline $pipeline, Retriever $retriever): RedirectResponse|View
    {
        // ?q[]=x のような配列は '' にする（検証で弾き、画面に出すときのエスケープで落ちないように）
        $input = collect(['scope', 'q', 'top_k', 'min_similarity', 'doc_type', 'score', 'action', 'compare_without_rag'])
            ->mapWithKeys(fn (string $key) => [$key => is_array($v = $request->input($key)) ? '' : $v])
            ->all();

        // 入力の誤りは埋め込み API を呼ぶ前に止める（エラーを付けてフォームへ戻る）
        $form = $this->validator($input)->validate();

        $options = SearchOptions::fromConfig([
            'top_k' => (int) $form['top_k'],
            'min_similarity' => (float) $form['min_similarity'],
            'score' => $form['score'],
            'document_type' => ($form['doc_type'] ?? null) ?: null,
        ]);
        $isJob = $form['scope'] === Retriever::SCOPE_JOB;
        $searchOnly = ($form['action'] ?? null) === 'search';
        // 「検索だけ」のときは Claude を呼ばないので、RAGなし比較は付けない
        $compare = ! $searchOnly && ! empty($form['compare_without_rag']);

        return $this->runPipeline(
            fn () => $isJob
                ? $pipeline->searchJobs($form['q'], $options)
                : $pipeline->ask($form['q'], $options, (int) config('rag.answer.chunks_per_candidate'), $searchOnly, $compare),
            fn (RagResult $result) => view('search', [
                'form' => $input,
                ...$this->resultViewData($result, $retriever),
            ]),
        );
    }

    /**
     * フォームの値。直前の入力（エラーで戻ったとき）→ 表示中の記録の条件 → 設定の初期値 の順に使う
     *
     * @return array<string, mixed>
     */
    private function form(?QueryLog $log): array
    {
        $defaults = config('rag.search');
        // 求人票から探した記録（元の求人票が消えたときだけここで表示する）は、フォームに入れない。
        // 検索に使った文は質問ではなく、1000文字を超えることもあるため
        if ($log?->mode === QueryLog::MODE_JOB_MATCH) {
            $log = null;
        }
        $params = $log?->params ?? [];
        $values = [
            'scope' => $log?->mode === QueryLog::MODE_JOB_SEARCH ? Retriever::SCOPE_JOB : Retriever::SCOPE_CANDIDATE,
            'q' => $log?->question ?? '',
            'top_k' => $params['top_k'] ?? $defaults['top_k'],
            'min_similarity' => $params['min_similarity'] ?? $defaults['min_similarity'],
            'doc_type' => $params['document_type'] ?? '',
            'score' => $params['score'] ?? $defaults['candidate_score'],
            'compare_without_rag' => (bool) ($params['compare_without_rag'] ?? false),
        ];

        // 戻ってきた入力が配列（?q[]=x）なら '' にする（エスケープで落ちないように）
        return collect($values)->map(fn ($value, string $key) => is_array($old = old($key, $value)) ? '' : $old)->all();
    }

    private function validator(array $input): ValidationValidator
    {
        $validator = Validator::make($input, [
            'scope' => ['required', Rule::in([Retriever::SCOPE_CANDIDATE, Retriever::SCOPE_JOB])],
            'q' => ['required', 'string', 'max:1000'],
            'top_k' => ['required', 'integer', 'min:1', 'max:50'],
            'min_similarity' => ['required', 'numeric', 'min:-1', 'max:1'],
            'doc_type' => ['nullable', Rule::in([Document::TYPE_RESUME, Document::TYPE_CAREER_HISTORY])],
            'score' => ['required', Rule::in(GroupRanker::SCORE_METHODS)],
            'action' => ['nullable', Rule::in(['ask', 'search'])],
            'compare_without_rag' => ['nullable', 'boolean'],
        ], [
            // lang ファイルを入れていないので、この画面で使う規則のメッセージだけ日本語で書く
            'required' => ':attribute を入力してください。',
            'string' => ':attribute は文字列で入力してください。',
            'max' => ':attribute は :max 以下にしてください。',
            'min' => ':attribute は :min 以上にしてください。',
            'integer' => ':attribute は整数で入力してください。',
            'numeric' => ':attribute は数値で入力してください。',
            'in' => ':attribute の値が正しくありません。',
            'boolean' => ':attribute の値が正しくありません。',
            'q.max' => ':attribute は :max 文字以内にしてください。',
        ], [
            'scope' => '検索対象', 'q' => '質問', 'top_k' => '表示する件数',
            'min_similarity' => '類似度の下限', 'doc_type' => '検索する文書', 'score' => 'スコアの決め方',
            'action' => '実行の種類', 'compare_without_rag' => 'RAGなし比較',
        ]);
        // 求人票には文書種別の絞り込みがない（Retriever も受け付けない）
        $validator->after(function ($v) use ($input) {
            if ($input['scope'] === Retriever::SCOPE_JOB && ! empty($input['doc_type'])) {
                $v->errors()->add('doc_type', '求人票を検索するときは、検索する文書を指定できません。');
            }
        });

        return $validator;
    }
}
