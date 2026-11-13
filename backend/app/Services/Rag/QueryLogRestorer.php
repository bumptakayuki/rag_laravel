<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\QueryLog;

/**
 * queries の1行から、画面に出す RagResult を組み立て直す（履歴の表示・実行直後の表示）。
 *
 * 実行直後の画面も、記録した行を読み直して表示する（POST → リダイレクト → この復元）。
 * こうすると「実行した直後に見た結果」と「履歴から開いた結果」が同じ表示になる。
 *
 * - スコア・ヒット数・種別ごとのヒット数は、記録した値をそのまま使う（設定が後で変わっても当時の値を出す）
 * - 各グループのヒットチャンクは、記録した1段目のチャンクのうち「同じ候補者（求人票）」かつ「類似度の下限以上」のもの。
 *   集約（GroupRanker）が使ったチャンクと同じになる。本文・見出しは今の chunks から読む。
 *   ただし記録の類似度は小数6桁に丸めているので、下限ちょうど付近（0.2999996 → 0.3 など）では
 *   実行時と1件ずれることがある。学習用の表示としては許容し、グループごとのチャンク ID は記録していない
 * - 取り込み直しでチャンクが消えていたら、そのチャンクは出さずに数だけ数える（missingChunks）
 */
class QueryLogRestorer
{
    public function restore(QueryLog $log): RagResult
    {
        $params = $log->params ?? [];
        $retrieved = $log->retrieved ?? [];
        $isJobSearch = $log->mode === QueryLog::MODE_JOB_SEARCH;

        $options = SearchOptions::fromConfig([
            'chunk_limit' => $params['chunk_limit'] ?? null,
            'top_k' => $params['top_k'] ?? null,
            'min_similarity' => $params['min_similarity'] ?? null,
            'score' => $params['score'] ?? null,
            'document_type' => $params['document_type'] ?? null,
        ]);

        // 1段目のチャンクを、今の chunks の本文・見出しと合わせて RetrievedChunk に戻す
        $stored = $retrieved['chunks'] ?? [];
        $rows = Chunk::with('document:id,candidate_id,type')
            ->whereIn('id', array_column($stored, 'chunk_id'))
            ->get()
            ->keyBy('id');

        $chunks = [];
        $missing = 0;
        foreach ($stored as $entry) {
            $row = $rows[$entry['chunk_id']] ?? null;
            if ($row === null || $row->document === null) {
                $missing++;

                continue;
            }
            $chunks[$row->id] = new RetrievedChunk(
                chunkId: $row->id,
                documentId: $row->document_id,
                candidateId: $row->document->candidate_id,
                documentType: $row->document->type,
                chunkIndex: $row->chunk_index,
                section: $row->metadata['section'] ?? null,
                content: $row->content,
                similarity: (float) $entry['similarity'],
            );
        }

        $ranking = new RankResult(
            groups: $this->groups($retrieved, $chunks, $options->minSimilarity, $isJobSearch),
            retrieved: (int) ($retrieved['counts']['retrieved'] ?? count($stored)),
            kept: (int) ($retrieved['counts']['kept'] ?? 0),
            totalGroups: (int) ($retrieved['counts']['total_groups'] ?? 0),
        );

        $prompt = $log->prompt === null ? null : $this->prompt($log->prompt, $retrieved['sources'] ?? [], $chunks);
        $unknown = ($prompt === null || $log->answer === null)
            ? []
            : array_values(array_filter(Prompt::citedNumbers($log->answer), fn (int $n) => $prompt->source($n) === null && ! $this->wasSent($n, $retrieved)));

        return new RagResult(
            queryLog: $log,
            searchText: $log->question,
            options: $options,
            ranking: $ranking,
            prompt: $prompt,
            answer: $log->answer,
            unknownCitations: $unknown,
            latencyMs: array_map('floatval', $log->latency_ms ?? []),
            mode: $log->mode,
            target: $retrieved['target'] ?? null,
            embedding: $retrieved['embedding'] ?? null,
            missingChunks: $missing,
        );
    }

    /**
     * @param  array<int, RetrievedChunk>  $chunks  chunk_id => チャンク
     * @return list<RankedGroup>
     */
    private function groups(array $retrieved, array $chunks, float $minSimilarity, bool $isJobSearch): array
    {
        [$groupsKey, $idKey] = $isJobSearch ? ['jobs', 'document_id'] : ['candidates', 'candidate_id'];

        $groups = [];
        foreach ($retrieved[$groupsKey] ?? [] as $entry) {
            $key = (int) $entry[$idKey];
            $members = array_values(array_filter(
                $chunks,
                fn (RetrievedChunk $c) => ($isJobSearch ? $c->documentId : $c->candidateId) === $key && $c->similarity >= $minSimilarity,
            ));
            // 集約と同じ並び（類似度の高い順、同じならチャンク ID の小さい順）
            usort($members, fn (RetrievedChunk $a, RetrievedChunk $b) => [$b->similarity, $a->chunkId] <=> [$a->similarity, $b->chunkId]);

            $groups[] = new RankedGroup(
                key: $key,
                score: (float) $entry['score'],
                maxSimilarity: (float) $entry['max_similarity'],
                hits: (int) $entry['hits'],
                hitsByType: $entry['hits_by_type'] ?? [],
                chunks: $members,
            );
        }

        return $groups;
    }

    /**
     * 記録した全文（Prompt::fullText() の形）と資料番号の対応から Prompt に戻す。
     * 消えたチャンクの資料は対応表に入れない（番号は wasSent で「送った番号」として扱う）
     *
     * @param  list<array{number: int, chunk_id: int, candidate_id: int}>  $sources
     * @param  array<int, RetrievedChunk>  $chunks
     */
    private function prompt(string $fullText, array $sources, array $chunks): Prompt
    {
        $system = '';
        $user = $fullText;
        if (preg_match('/\A\[system\]\n(.*?)\n\n\[user\]\n(.*)\z/s', $fullText, $m) === 1) {
            [$system, $user] = [$m[1], $m[2]];
        }

        // Prompt::source() は番号 - 1 の位置を引くので、番号の位置に詰めて入れる（消えた番号は飛ばしたまま）
        $list = [];
        foreach ($sources as $source) {
            $chunk = $chunks[$source['chunk_id']] ?? null;
            if ($chunk !== null) {
                $list[$source['number'] - 1] = new PromptSource((int) $source['number'], (int) $source['candidate_id'], $chunk);
            }
        }
        ksort($list);

        return new Prompt($system, $user, $list);
    }

    /** その番号の資料をプロンプトに入れていたか（チャンクが消えていても、送っていれば Claude の誤りではない） */
    private function wasSent(int $number, array $retrieved): bool
    {
        return in_array($number, array_column($retrieved['sources'] ?? [], 'number'), true);
    }
}
