<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RunsRagPipeline;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\JobMatcher;
use App\Services\Rag\RagPipeline;
use App\Services\Rag\RagResult;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 履歴（/history。要件定義書 第4.4章）。queries の一覧と、同じ設定での再実行。
 *
 *   GET  /history                  一覧（新しい順・20件ずつ）。「詳細」は記録を表示する画面（質問画面 / 求人票詳細）へ
 *   POST /history/{id}/rerun       記録の質問・条件で RagPipeline を実行し、新しい記録として結果の画面へ
 *
 * 詳細は専用の画面を作らず、実行直後と同じ画面（QueryLogRestorer で組み立て直す）で表示する。
 */
class HistoryController extends Controller
{
    use RunsRagPipeline;

    public function index(): View
    {
        $logs = QueryLog::with('sourceDocument')->orderByDesc('id')->paginate(20);

        // 1位の候補者・求人票の名前
        $first = fn (QueryLog $log) => $log->mode === QueryLog::MODE_JOB_SEARCH
            ? ($log->retrieved['jobs'][0]['document_id'] ?? null)
            : ($log->retrieved['candidates'][0]['candidate_id'] ?? null);
        $candidateIds = $logs->getCollection()->reject(fn ($l) => $l->mode === QueryLog::MODE_JOB_SEARCH)->map($first)->filter();
        $documentIds = $logs->getCollection()->filter(fn ($l) => $l->mode === QueryLog::MODE_JOB_SEARCH)->map($first)->filter();

        return view('history.index', [
            'logs' => $logs,
            'candidates' => Candidate::whereIn('id', $candidateIds)->get()->keyBy('id'),
            'documents' => Document::whereIn('id', $documentIds)->get()->keyBy('id'),
        ]);
    }

    public function rerun(QueryLog $queryLog, RagPipeline $pipeline, Retriever $retriever): RedirectResponse|View
    {
        $params = $queryLog->params ?? [];
        $options = fn () => SearchOptions::fromConfig([
            'chunk_limit' => $params['chunk_limit'] ?? null,
            'top_k' => $params['top_k'] ?? null,
            'min_similarity' => $params['min_similarity'] ?? null,
            'score' => $params['score'] ?? null,
            'document_type' => $params['document_type'] ?? null,
        ]);

        if ($queryLog->mode === QueryLog::MODE_JOB_MATCH && $queryLog->sourceDocument === null) {
            return back()->with('api_error', '元の求人票が取り込み直しで削除されているため、再実行できません。');
        }

        return $this->runPipeline(
            fn () => match ($queryLog->mode) {
                QueryLog::MODE_JOB_SEARCH => $pipeline->searchJobs($queryLog->question, $options()),
                QueryLog::MODE_JOB_MATCH => $pipeline->match(
                    $queryLog->sourceDocument,
                    $params['source'] ?? JobMatcher::SOURCE_REQUIRED,
                    $options(),
                ),
                default => $pipeline->ask(
                    $queryLog->question,
                    $options(),
                    (int) ($params['chunks_per_candidate'] ?? config('rag.answer.chunks_per_candidate')),
                ),
            },
            // 記録に失敗したときは質問画面の形でその場に出す
            fn (RagResult $result) => view('search', [
                'form' => [
                    'scope' => $result->isJobSearch() ? Retriever::SCOPE_JOB : Retriever::SCOPE_CANDIDATE,
                    'q' => $result->searchText,
                    'top_k' => $result->options->topK,
                    'min_similarity' => $result->options->minSimilarity,
                    'doc_type' => $result->options->documentType ?? '',
                    'score' => $result->options->scoreMethod,
                ],
                ...$this->resultViewData($result, $retriever),
            ]),
        );
    }
}
