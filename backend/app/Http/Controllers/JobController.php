<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RunsRagPipeline;
use App\Http\Requests\JobMatchRequest;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\DocumentOutline;
use App\Services\Rag\JobMatcher;
use App\Services\Rag\QueryLogRestorer;
use App\Services\Rag\RagPipeline;
use App\Services\Rag\RagResult;
use App\Services\Rag\Retriever;
use App\Services\Rag\SearchOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * 求人票一覧（/jobs）と求人票詳細（/jobs/{id}）。要件定義書 第4.4章。
 *
 *   GET  /jobs?q=職種名          一覧。職種名（文書のタイトル）で絞り込む
 *   GET  /jobs/{id}?query={id}   全文を見出しごとに出し、各見出しがどのチャンクに分かれたかを示す。
 *                                query を渡すと、その求人マッチングの候補者ランキングを出す
 *   POST /jobs/{id}/match        RagPipeline::match で候補者を探し、記録して GET /jobs/{id}?query={id} へ
 */
class JobController extends Controller
{
    use RunsRagPipeline;

    /** 検索に使う部分の表示名 */
    public const SOURCE_LABELS = [
        JobMatcher::SOURCE_REQUIRED => '必須条件のみ',
        JobMatcher::SOURCE_REQUIRED_PREFERRED => '必須＋歓迎',
        JobMatcher::SOURCE_FULL => '全文',
    ];

    public function index(Request $request): View
    {
        $filter = is_string($request->query('q')) ? trim($request->query('q')) : '';

        $query = Document::where('type', Document::TYPE_JOB)->withCount('chunks')->orderBy('id');
        if ($filter !== '') {
            // LIKE の特殊文字（% _ \）はそのままの文字として探す
            $query->where('title', 'ilike', '%'.addcslashes($filter, '%_\\').'%');
        }
        $jobs = $query->get();

        return view('jobs.index', [
            'filter' => $filter,
            'jobs' => $jobs,
            'jobCount' => Document::where('type', Document::TYPE_JOB)->count(),
            'chunkTotal' => Chunk::whereHas('document', fn ($q) => $q->where('type', Document::TYPE_JOB))->count(),
        ]);
    }

    public function show(Request $request, Document $job, QueryLogRestorer $restorer, Retriever $retriever): View
    {
        abort_unless($job->type === Document::TYPE_JOB, 404);

        $log = null;
        if ($request->filled('query')) {
            $id = $request->query('query');
            abort_unless(is_string($id) && ctype_digit($id), 404);
            // この求人票から探した記録だけを表示する
            $log = QueryLog::where('mode', QueryLog::MODE_JOB_MATCH)->where('source_document_id', $job->id)->findOrFail((int) $id);
        }

        $result = $log === null ? null : $restorer->restore($log);
        $source = old('source', $log?->params['source'] ?? config('rag.search.job_match_source'));

        return view('jobs.show', $this->showData($job, is_string($source) ? $source : JobMatcher::SOURCE_REQUIRED, $result, $retriever));
    }

    public function match(JobMatchRequest $request, Document $job, RagPipeline $pipeline, Retriever $retriever): RedirectResponse|View
    {
        // 求人票でない文書の 404 と入力の検証は JobMatchRequest で行う
        $source = $request->validated('source');

        // 件数・下限・スコアの決め方は設定の初期値（rag:match と同じ）
        return $this->runPipeline(
            fn () => $pipeline->match($job, $source, SearchOptions::fromConfig()),
            fn (RagResult $result) => view('jobs.show', $this->showData($job, $source, $result, $retriever)),
        );
    }

    /**
     * 求人票の「職種名」。本文の「募集職種：」の行、なければタイトルから「求人票：」を除いたもの
     */
    public static function jobTitle(Document $job): string
    {
        if (preg_match('/^募集職種[：:]\s*(.+?)\s*$/mu', $job->content, $m) === 1) {
            return $m[1];
        }

        return preg_replace('/^求人票[：:]\s*/u', '', $job->title);
    }

    /**
     * 「主な必須スキル」= 必須条件の欄の最初の項目（箇条書きの記号は外す）。欄がなければ null
     */
    public static function mainRequirement(Document $job): ?string
    {
        $required = JobMatcher::sections($job->content)[JobMatcher::SOURCE_SECTIONS[JobMatcher::SOURCE_REQUIRED][0]] ?? null;
        foreach (explode("\n", (string) $required) as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*+]|\d+\.)\s+/u', '', $line));
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function showData(Document $job, string $source, ?RagResult $result, Retriever $retriever): array
    {
        $job->load('chunks');
        $outline = DocumentOutline::build($job->content, $job->chunks->map(fn (Chunk $c) => [
            'id' => $c->id,
            'chunk_index' => $c->chunk_index,
            'section' => $c->metadata['section'] ?? null,
            'content' => $c->content,
            'overlap' => (int) ($c->metadata['chunk_overlap'] ?? config('rag.chunk.overlap')),
        ])->all());

        if (! array_key_exists($source, JobMatcher::SOURCE_SECTIONS)) {
            $source = JobMatcher::SOURCE_REQUIRED;
        }

        // 選んだ部分で実際に検索に使う文（見出しがない求人票ではエラー文を出す）
        try {
            $sourceText = app(JobMatcher::class)->sourceText($job, $source);
            $sourceError = null;
        } catch (InvalidArgumentException $e) {
            $sourceText = null;
            $sourceError = $e->getMessage();
        }

        return [
            'job' => $job,
            'jobTitle' => self::jobTitle($job),
            'outline' => $outline,
            'source' => $source,
            // 検索に使う見出し（null = 全文）。全文の表示でハイライトする
            'usedSections' => JobMatcher::SOURCE_SECTIONS[$source],
            'sourceText' => $sourceText,
            'sourceError' => $sourceError,
            ...($result === null ? ['result' => null] : $this->resultViewData($result, $retriever)),
        ];
    }
}
