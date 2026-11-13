<?php

namespace App\Support;

use App\Services\Rag\GroupRanker;

/**
 * 画面の表示用の書式（複数の Blade で使うものだけ）。
 */
final class RagFormat
{
    /** スコアの決め方の短い名前（フォームの切り替え） */
    public const SCORE_LABELS = [
        GroupRanker::SCORE_MAX => '最大値',
        GroupRanker::SCORE_AVG3 => '上位3平均',
        GroupRanker::SCORE_MAX_PLUS_HITS => '最大＋件数',
    ];

    /**
     * 工程の表示名と表示順。queries.latency_ms（jsonb）はキーの順番を保たないので、ここで順番を決める
     */
    public const STAGES = [
        'embed' => 'ベクトル化',
        'search' => 'チャンク検索',
        'rank' => '集約',
        'prompt' => 'プロンプト組み立て',
        'generate' => '回答生成',
        'generate_without_rag' => 'RAGなし回答（比較）',
    ];

    /** 所要時間（ミリ秒）を「12 ms」「2.7 s」の形にする */
    public static function ms(float $ms): string
    {
        return $ms >= 1000 ? number_format($ms / 1000, 1).' s' : number_format($ms, $ms < 10 ? 1 : 0).' ms';
    }

    /** スコアの決め方の説明 */
    public static function scoreNote(string $method): string
    {
        return match ($method) {
            GroupRanker::SCORE_MAX => 'その中のチャンクの最大類似度',
            GroupRanker::SCORE_AVG3 => '類似度の上位3チャンクの平均',
            GroupRanker::SCORE_MAX_PLUS_HITS => '最大類似度 ＋ 2件目以降のヒット1件につき '.config('rag.search.hit_bonus').'（'.config('rag.search.hit_bonus_max_hits').'件まで）',
            default => $method,
        };
    }

    /**
     * 工程ごとの時間を表示順に並べる（記録にない工程は飛ばす）
     *
     * @param  array<string, float>  $latency
     * @return array<string, float> 工程キー => ミリ秒
     */
    public static function orderedStages(array $latency): array
    {
        $ordered = [];
        foreach (array_keys(self::STAGES) as $stage) {
            if (isset($latency[$stage])) {
                $ordered[$stage] = (float) $latency[$stage];
            }
        }

        return $ordered;
    }

    /** 合計時間。記録の total があればそれ、なければ工程の和 */
    public static function total(array $latency): float
    {
        return (float) ($latency['total'] ?? array_sum(self::orderedStages($latency)));
    }
}
