<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Document;
use App\Services\Rag\Contracts\Embedder;
use App\Services\Rag\GroupRanker;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * 質問画面（/）の検索部分（RS-004b）。回答生成と queries への記録は RS-005 で足す。
 *
 * 処理は rag:search と同じ部品（Embedder → Retriever → Ranker）を使う。
 * 画面で「検索の流れ」を見せるため、工程ごとに分けて呼び、所要時間を測る。
 */
class SearchController extends Controller
{
    public function index(Request $request, Embedder $embedder, Retriever $retriever): View
    {
        $defaults = config('rag.search');
        // ?q[]=x のような配列は '' にする（検証で弾き、画面に出すときのエスケープで落ちないように）
        $input = fn (string $key, mixed $default = null) => is_array($value = $request->query($key, $default)) ? '' : $value;
        $form = [
            'scope' => $input('scope', Retriever::SCOPE_CANDIDATE),
            'q' => $input('q'),
            'top_k' => $input('top_k', $defaults['top_k']),
            'min_similarity' => $input('min_similarity', $defaults['min_similarity']),
            'doc_type' => $input('doc_type', ''),
            'score' => $input('score', $defaults['candidate_score']),
        ];
        $page = ['form' => $form, 'result' => null];

        // 質問がないとき（最初の表示）はフォームだけ出す
        if (! $request->has('q')) {
            return view('search', $page);
        }

        $validator = Validator::make($form, [
            'scope' => ['required', Rule::in([Retriever::SCOPE_CANDIDATE, Retriever::SCOPE_JOB])],
            'q' => ['required', 'string', 'max:1000'],
            'top_k' => ['required', 'integer', 'min:1', 'max:50'],
            'min_similarity' => ['required', 'numeric', 'min:-1', 'max:1'],
            'doc_type' => ['nullable', Rule::in([Document::TYPE_RESUME, Document::TYPE_CAREER_HISTORY])],
            'score' => ['required', Rule::in(GroupRanker::SCORE_METHODS)],
        ], [
            // lang ファイルを入れていないので、この画面で使う規則のメッセージだけ日本語で書く
            'required' => ':attribute を入力してください。',
            'string' => ':attribute は文字列で入力してください。',
            'max' => ':attribute は :max 以下にしてください。',
            'min' => ':attribute は :min 以上にしてください。',
            'integer' => ':attribute は整数で入力してください。',
            'numeric' => ':attribute は数値で入力してください。',
            'in' => ':attribute の値が正しくありません。',
            'q.max' => ':attribute は :max 文字以内にしてください。',
        ], [
            'scope' => '検索対象', 'q' => '質問', 'top_k' => '表示する件数',
            'min_similarity' => '類似度の下限', 'doc_type' => '検索する文書', 'score' => 'スコアの決め方',
        ]);
        // 求人票には文書種別の絞り込みがない（Retriever も受け付けない）
        $validator->after(function ($v) use ($form) {
            if ($form['scope'] === Retriever::SCOPE_JOB && ! empty($form['doc_type'])) {
                $v->errors()->add('doc_type', '求人票を検索するときは、検索する文書を指定できません。');
            }
        });

        // 入力の誤りは埋め込み API を呼ぶ前に止める
        if ($validator->fails()) {
            return $this->withErrors($page, $validator->errors());
        }

        $isJob = $form['scope'] === Retriever::SCOPE_JOB;
        try {
            $options = SearchOptions::fromConfig([
                'top_k' => (int) $form['top_k'],
                'min_similarity' => (float) $form['min_similarity'],
                'score' => $form['score'],
                'document_type' => $form['doc_type'] ?: null,
            ]);
            $ranker = $isJob ? $options->documentRanker() : $options->candidateRanker();
            $sql = $retriever->sqlForDisplay($form['scope'], $options->chunkLimit, $options->documentType);
        } catch (InvalidArgumentException $e) {
            return $this->withErrors($page, new MessageBag(['q' => [$e->getMessage()]]));
        }

        // 1. ベクトル化（外部 API）
        $started = hrtime(true);
        $vector = $embedder->embed([$form['q']])[0];
        $embedMs = $this->elapsedMs($started);

        // 2. チャンク検索（生SQL）
        $started = hrtime(true);
        $chunks = $retriever->retrieveByVector($vector, $form['scope'], $options->chunkLimit, $options->documentType);
        $searchMs = $this->elapsedMs($started);

        // 3. 集約（PHP）
        $started = hrtime(true);
        $ranking = $ranker->rank($chunks);
        $rankMs = $this->elapsedMs($started);

        $keys = array_map(fn ($g) => $g->key, $ranking->groups);

        return view('search', [...$page, 'result' => [
            'isJob' => $isJob,
            'options' => $options,
            'ranking' => $ranking,
            'sql' => $sql,
            'vectorHead' => array_slice($vector, 0, 3),
            'dimensions' => count($vector),
            'target' => $this->targetCounts($isJob, $options->documentType),
            'timings' => ['embed' => $embedMs, 'search' => $searchMs, 'rank' => $rankMs],
            'candidates' => $isJob ? collect() : Candidate::whereIn('id', $keys)->get()->keyBy('id'),
            'documents' => $isJob ? Document::whereIn('id', $keys)->get()->keyBy('id') : collect(),
        ]]);
    }

    /** 入力エラーを付けてフォームを出し直す（GET なのでリダイレクトせず、その場で表示する） */
    private function withErrors(array $page, MessageBag $messages): View
    {
        return view('search', [...$page, 'errors' => (new ViewErrorBag)->put('default', $messages)]);
    }

    private function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1e6;
    }

    /** 検索対象になった文書数とチャンク数（埋め込み済みのものだけ） */
    private function targetCounts(bool $isJob, ?string $documentType): object
    {
        $query = DB::table('chunks as c')
            ->join('documents as d', 'd.id', '=', 'c.document_id')
            ->whereNotNull('c.embedding');
        $isJob ? $query->where('d.type', Document::TYPE_JOB) : $query->whereNotNull('d.candidate_id');
        if ($documentType !== null) {
            $query->where('d.type', $documentType);
        }

        return $query->selectRaw('count(distinct d.id) as documents, count(*) as chunks')->first();
    }
}
