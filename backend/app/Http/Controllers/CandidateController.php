<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\DocumentOutline;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 候補者詳細（/candidates/{id}?query={query_id}。要件定義書 第4.4章）。
 *
 * 履歴書と職務経歴書の全文を両方出す。query を渡すと、その検索でヒットしたチャンクを
 * ハイライトして類似度を横に出す。検索結果のカードからは "#chunk-{id}" つきで該当箇所へ直接移動する。
 * どちらのタブを開くかは画面の JavaScript が決める（URL の #chunk-{id} → 一番近いヒット → 職務経歴書）。
 * サーバーではタブを判定しない
 */
class CandidateController extends Controller
{
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
