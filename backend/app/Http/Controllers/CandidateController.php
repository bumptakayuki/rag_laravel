<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\DocumentOutline;
use App\Services\Rag\JobMatcher;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 候補者一覧（/candidates?q=&job=&skill=&keyword=）と候補者詳細（/candidates/{id}?query={query_id}。要件定義書 第4.4章）。
 *
 * 一覧は取り込み済みの全候補者をコード順に出す。絞り込みは文字列の一致だけ（RAG の検索は使わない。外部 API も呼ばない）。
 *
 * 詳細:
 *
 * 履歴書と職務経歴書の全文を両方出す。query を渡すと、その検索でヒットしたチャンクを
 * ハイライトして類似度を横に出す。検索結果のカードからは "#chunk-{id}" つきで該当箇所へ直接移動する。
 * どちらのタブを開くかは画面の JavaScript が決める（URL の #chunk-{id} → 一番近いヒット → 職務経歴書）。
 * サーバーではタブを判定しない
 */
class CandidateController extends Controller
{
    /** 職務経歴書・履歴書で希望職種を書く行（「希望職種：バックエンドエンジニア」） */
    private const DESIRED_JOB_PATTERN = '/^希望職種[：:]\s*(.+?)\s*$/mu';

    /** スキルを書く見出し */
    private const SKILL_SECTION = 'スキル';

    /**
     * 一覧。絞り込みはすべて文字列の一致（埋め込み・Claude は使わない）で、指定したものを AND で組み合わせる。
     *   q        コードか名前の部分一致
     *   job      希望職種の完全一致（選択肢は取り込み済みの希望職種）
     *   skill    職務経歴書・履歴書の「スキル」欄に含まれる（大文字小文字は区別しない）
     *   keyword  職務経歴書・履歴書の全文に含まれる（大文字小文字は区別しない）
     * 職種・スキル・キーワードは本文を読み込んで PHP で判定する（候補者は数十名の想定）
     */
    public function index(Request $request): View
    {
        $filters = [];
        foreach (['q', 'job', 'skill', 'keyword'] as $name) {
            $filters[$name] = is_string($request->query($name)) ? trim($request->query($name)) : '';
        }

        $query = Candidate::with('documents:id,candidate_id,type,content,created_at')->withCount('chunks')->orderBy('code');
        if ($filters['q'] !== '') {
            // コードか名前の部分一致。LIKE の特殊文字（% _ \）はそのままの文字として探す
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }
        $all = $query->get();

        // 職種の選択肢は、コード・名前の絞り込みに関係なく全候補者から作る
        $jobOptions = Candidate::with('documents:id,candidate_id,type,content')->get()
            ->map(fn (Candidate $c) => self::desiredJob($c))->filter()->unique()->sort()->values()->all();

        $rows = [];
        foreach ($all as $candidate) {
            $desiredJob = self::desiredJob($candidate);
            if ($filters['job'] !== '' && $desiredJob !== $filters['job']) {
                continue;
            }
            $skillLines = $filters['skill'] === '' ? [] : self::matchingSkillLines($candidate, $filters['skill']);
            if ($filters['skill'] !== '' && $skillLines === []) {
                continue;
            }
            if ($filters['keyword'] !== '' && ! $candidate->documents->contains(
                fn (Document $d) => mb_stripos($d->content, $filters['keyword']) !== false
            )) {
                continue;
            }
            $rows[] = ['candidate' => $candidate, 'desiredJob' => $desiredJob, 'skillLines' => $skillLines];
        }

        return view('candidates.index', [
            'filters' => $filters,
            'filtered' => array_filter($filters, fn (string $v) => $v !== '') !== [],
            'rows' => $rows,
            'jobOptions' => $jobOptions,
            'candidateCount' => Candidate::count(),
            'chunkTotal' => Chunk::whereHas('document', fn ($q) => $q->whereNotNull('candidate_id'))->count(),
        ]);
    }

    /**
     * 希望職種。職務経歴書 → 履歴書 の順に「希望職種：」の行を探す。なければ null
     */
    public static function desiredJob(Candidate $candidate): ?string
    {
        foreach (Document::CANDIDATE_TYPES as $type) {
            $document = $candidate->documents->firstWhere('type', $type);
            if ($document !== null && preg_match(self::DESIRED_JOB_PATTERN, $document->content, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * 「スキル」欄の行のうち、語を含むもの（箇条書きの記号は外す。職務経歴書 → 履歴書 の順、重複は除く）
     *
     * @return list<string>
     */
    public static function matchingSkillLines(Candidate $candidate, string $term): array
    {
        $lines = [];
        foreach (Document::CANDIDATE_TYPES as $type) {
            $document = $candidate->documents->firstWhere('type', $type);
            $section = $document === null ? null : (JobMatcher::sections($document->content)[self::SKILL_SECTION] ?? null);
            foreach (explode("\n", (string) $section) as $line) {
                $line = trim(preg_replace('/^\s*(?:[-*+]|\d+\.)\s+/u', '', $line));
                if ($line !== '' && mb_stripos($line, $term) !== false && ! in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    public function show(Request $request, Candidate $candidate): View
    {
        $documents = $candidate->documents()->with('chunks')->get()->keyBy('type');

        $log = null;
        if ($request->filled('query')) {
            $id = $request->query('query');
            abort_unless(is_string($id) && ctype_digit($id), 404);
            $log = QueryLog::findOrFail((int) $id);
        }

        $hits = $log === null ? [] : $this->hits($log, $candidate, $documents->all());

        // 種別ごとの全文。文書がない種別は null
        $outlines = [];
        foreach (Document::CANDIDATE_TYPES as $type) {
            $document = $documents[$type] ?? null;
            $outlines[$type] = $document === null ? null : DocumentOutline::build(
                $document->content,
                $document->chunks->map(fn (Chunk $c) => [
                    'id' => $c->id,
                    'chunk_index' => $c->chunk_index,
                    'section' => $c->metadata['section'] ?? null,
                    'content' => $c->content,
                    'overlap' => (int) ($c->metadata['chunk_overlap'] ?? config('rag.chunk.overlap')),
                ])->all(),
            );
        }

        return view('candidates.show', [
            'candidate' => $candidate,
            'documents' => $documents,
            'outlines' => $outlines,
            'log' => $log,
            'hits' => $hits,
            'hitsByChunk' => collect($hits)->keyBy('chunk_id')->all(),
            'summary' => $log === null ? null : $this->summary($log, $candidate),
        ]);
    }

    /**
     * その検索でこの候補者にヒットしたチャンク（類似度の下限以上。類似度の高い順）。
     * 記録の類似度は小数6桁に丸めてあるので、下限ちょうど付近では実行時と1件ずれることがある（QueryLogRestorer と同じ）。
     * 回答の資料としてプロンプトに入れたチャンクには資料番号も付ける
     *
     * @param  array<string, Document>  $documents  この候補者の文書（種別 => 文書。チャンクを読み込み済み）
     * @return list<array{chunk_id: int, type: string, section: string|null, chunk_index: int, similarity: float, cited: list<int>}>
     */
    private function hits(QueryLog $log, Candidate $candidate, array $documents): array
    {
        // chunk_id => [チャンク, 文書種別]
        $chunks = [];
        foreach ($documents as $type => $document) {
            foreach ($document->chunks as $chunk) {
                $chunks[$chunk->id] = [$chunk, $type];
            }
        }

        $min = (float) ($log->params['min_similarity'] ?? config('rag.search.min_similarity'));
        $cited = [];
        foreach ($log->retrieved['sources'] ?? [] as $source) {
            $cited[$source['chunk_id']][] = (int) $source['number'];
        }

        $hits = [];
        foreach ($log->retrieved['chunks'] ?? [] as $entry) {
            [$chunk, $type] = $chunks[$entry['chunk_id']] ?? [null, null];
            // 取り込み直しで消えたチャンクは出さない
            if ($entry['candidate_id'] !== $candidate->id || $chunk === null || $entry['similarity'] < $min) {
                continue;
            }
            $hits[] = [
                'chunk_id' => $chunk->id,
                'type' => $type,
                'section' => $chunk->metadata['section'] ?? null,
                'chunk_index' => $chunk->chunk_index,
                'similarity' => (float) $entry['similarity'],
                'cited' => $cited[$chunk->id] ?? [],
            ];
        }
        usort($hits, fn (array $a, array $b) => [$b['similarity'], $a['chunk_id']] <=> [$a['similarity'], $b['chunk_id']]);

        return $hits;
    }

    /** @return array{rank: int|null, score: float|null, hits: int|null} その検索での順位・スコア（上位に入っていなければ null） */
    private function summary(QueryLog $log, Candidate $candidate): array
    {
        foreach ($log->retrieved['candidates'] ?? [] as $i => $entry) {
            if ($entry['candidate_id'] === $candidate->id) {
                return ['rank' => $i + 1, 'score' => (float) $entry['score'], 'hits' => (int) $entry['hits']];
            }
        }

        return ['rank' => null, 'score' => null, 'hits' => null];
    }
}
