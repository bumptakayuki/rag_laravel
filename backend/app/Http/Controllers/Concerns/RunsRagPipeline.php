<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Candidate;
use App\Models\Document;
use App\Services\Rag\ExternalApiException;
use App\Services\Rag\RagResult;
use App\Services\Rag\RankedGroup;
use App\Services\Rag\Retriever;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * 画面から RagPipeline を実行し、結果の画面へ移る（質問画面・求人票詳細・履歴の再実行で共通）。
 *
 * - 記録できたら、その記録を表示する URL へリダイレクトする（POST → リダイレクト → GET。
 *   再読み込みで Claude をもう一度呼ばないように）
 * - 記録に失敗したら、結果は捨てずにその場で表示する（$renderUnrecorded）。RS-005 と同じ考え方
 * - 外部 API の失敗は、工程・ステータスコード・メッセージを画面に出す（要件定義書 第7章。ログは RagPipeline が出す）
 * - 引数の誤り（求人票に見出しがない等）も画面に出す。DB のエラーなどは捕まえずにそのまま投げる
 */
trait RunsRagPipeline
{
    /** 外部 API の工程名（ExternalApiException::$stage）の表示名 */
    private const STAGE_LABELS = [
        'embed' => '質問のベクトル化（OpenAI）',
        'generate' => '回答生成（Claude）',
    ];

    /**
     * @param  Closure(): RagResult  $run
     * @param  Closure(RagResult): View  $renderUnrecorded
     */
    protected function runPipeline(Closure $run, Closure $renderUnrecorded): RedirectResponse|View
    {
        try {
            $result = $run();
        } catch (ExternalApiException $e) {
            return back()->withInput()->with('api_error', sprintf(
                '外部 API の呼び出しに失敗しました（%s）: %s',
                self::STAGE_LABELS[$e->stage] ?? $e->stage,
                $e->getMessage(),
            ));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('api_error', '実行できませんでした: '.$e->getMessage());
        }

        if ($result->queryLog === null) {
            return $renderUnrecorded($result);
        }

        return redirect($result->queryLog->resultUrl());
    }

    /**
     * 結果の表示に使う値（候補者・求人票の名前、実行した SQL）
     *
     * @return array<string, mixed>
     */
    protected function resultViewData(RagResult $result, Retriever $retriever): array
    {
        $keys = array_map(fn (RankedGroup $g) => $g->key, $result->ranking->groups);
        $scope = $result->isJobSearch() ? Retriever::SCOPE_JOB : Retriever::SCOPE_CANDIDATE;

        return [
            'result' => $result,
            'candidates' => $result->isJobSearch() ? collect() : Candidate::whereIn('id', $keys)->get()->keyBy('id'),
            'documents' => $result->isJobSearch() ? Document::whereIn('id', $keys)->get()->keyBy('id') : collect(),
            // 表示用の SQL は今のコードで組み立てる（記録には SQL を入れていない）
            'sql' => $retriever->sqlForDisplay($scope, $result->options->chunkLimit, $result->options->documentType),
        ];
    }
}
