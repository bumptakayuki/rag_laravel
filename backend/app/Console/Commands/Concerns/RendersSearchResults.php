<?php

namespace App\Console\Commands\Concerns;

use App\Models\Candidate;
use App\Models\Document;
use App\Services\Rag\RankResult;
use App\Services\Rag\RetrievedChunk;
use App\Services\Rag\SearchOptions;
use Illuminate\Support\Str;

/**
 * rag:search / rag:match の結果表示。候補者（または求人票）ごとに、ヒットしたチャンクを
 * 文書種別・見出し・chunk_index・類似度つきで並べる（要件定義書 第4.2章・第8章ステップ4の基準）。
 */
trait RendersSearchResults
{
    private function renderConditions(string $scopeLabel, SearchOptions $options): void
    {
        $this->line(sprintf(
            '条件: 対象=%s / 文書=%s / chunk_limit=%d / 下限=%s / スコア=%s / top_k=%d',
            $scopeLabel,
            $options->documentType === null ? 'すべて' : Document::TYPE_LABELS[$options->documentType],
            $options->chunkLimit,
            $options->minSimilarity,
            $options->scoreMethod,
            $options->topK,
        ));
    }

    private function renderSummary(RankResult $result, string $unit): void
    {
        $this->line(sprintf(
            '取得 %d チャンク → 下限で除外後 %d チャンク → %d %s中 上位 %d %sを表示',
            $result->retrieved,
            $result->kept,
            $result->totalGroups,
            $unit,
            count($result->groups),
            $unit,
        ));
        $this->newLine();
    }

    /** 候補者ごとの結果 */
    private function renderCandidates(RankResult $result, bool $full): void
    {
        $this->renderSummary($result, '名');
        $candidates = Candidate::whereIn('id', array_map(fn ($g) => $g->key, $result->groups))->get()->keyBy('id');

        foreach ($result->groups as $rank => $group) {
            $candidate = $candidates[$group->key] ?? null;
            $this->info(sprintf(
                '%d. %s  スコア %.4f  ヒット %d（%s）',
                $rank + 1,
                $candidate ? "{$candidate->code} {$candidate->name}" : "候補者 id={$group->key}",
                $group->score,
                $group->hits,
                $this->hitsByTypeLabel($group->hitsByType),
            ));
            foreach ($group->chunks as $chunk) {
                $this->renderChunk($chunk, $full);
            }
            $this->newLine();
        }
    }

    /** 求人票ごとの結果 */
    private function renderJobs(RankResult $result, bool $full): void
    {
        $this->renderSummary($result, '件');
        $documents = Document::whereIn('id', array_map(fn ($g) => $g->key, $result->groups))->get()->keyBy('id');

        foreach ($result->groups as $rank => $group) {
            $document = $documents[$group->key] ?? null;
            $this->info(sprintf(
                '%d. [文書 %d] %s  スコア %.4f  ヒット %d',
                $rank + 1,
                $group->key,
                $document?->title ?? '',
                $group->score,
                $group->hits,
            ));
            foreach ($group->chunks as $chunk) {
                $this->renderChunk($chunk, $full);
            }
            $this->newLine();
        }
    }

    private function renderChunk(RetrievedChunk $chunk, bool $full): void
    {
        $content = str_replace("\n", ' ', $chunk->content);
        $this->line(sprintf(
            '   %.4f  %s・%s #%d  %s',
            $chunk->similarity,
            Document::TYPE_LABELS[$chunk->documentType] ?? $chunk->documentType,
            $chunk->section ?? '（見出しなし）',
            $chunk->chunkIndex,
            $full ? $content : Str::limit($content, 60),
        ));
    }

    /** @param  array<string, int>  $hitsByType */
    private function hitsByTypeLabel(array $hitsByType): string
    {
        $parts = [];
        foreach (Document::TYPE_LABELS as $type => $label) {
            if (isset($hitsByType[$type])) {
                $parts[] = "{$label} {$hitsByType[$type]}";
            }
        }

        return implode(' / ', $parts);
    }

    /**
     * queries に記録できたかを表示し、終了コードを返す。
     * 記録に失敗しても結果（回答）は先に表示してある。「実行ごとに記録する」を満たせていないので FAILURE にする
     */
    private function renderRecorded(?int $queryId, ?string $recordError): int
    {
        if ($queryId === null) {
            $this->error('結果を queries に記録できなかった（上の結果は表示のみ）: '.$recordError);

            return self::FAILURE;
        }
        $this->line("queries に記録した: id={$queryId}");

        return self::SUCCESS;
    }

    /** 整数のオプションを受け取る。未指定は null、整数でなければ例外（1.5 を黙って 1 にしない） */
    private function intOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (! preg_match('/\A\d+\z/', (string) $value)) {
            throw new \InvalidArgumentException("--{$name} には0以上の整数を指定すること: {$value}");
        }

        return (int) $value;
    }

    /** 小数のオプションを受け取る。未指定は null、数値でなければ例外 */
    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("--{$name} には数値を指定すること: {$value}");
        }

        return (float) $value;
    }
}
