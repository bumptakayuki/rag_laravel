<?php

namespace App\Services\Rag;

use App\Models\Document;
use InvalidArgumentException;

/**
 * 回答生成のプロンプトを組み立てる（要件定義書 第4.3章）。
 *
 * 上位 top_k 名の候補者について、ヒットしたチャンクを類似度の高い順に最大 chunks_per_candidate 件ずつ、
 * 候補者ごとにまとめて入れる。各チャンクには通し番号を振り「[1] (職務経歴書・職務経歴) 本文」の形にする。
 * Claude には「使った資料の番号を [1] の形で示す」よう指示し、番号から元のチャンクをたどれるようにする。
 *
 * DB・設定・外部 API を使わない純粋なクラス（候補者の表示名は呼び出し側が渡す）。
 */
class PromptBuilder
{
    /** 資料から答えられないときの決まり文句。system プロンプトで指示し、ヒット0件のときもこれを返す */
    public const NO_ANSWER = '資料からはわかりません';

    public const SYSTEM = <<<'TEXT'
        あなたは採用担当者を手伝うアシスタントです。ユーザーのメッセージの「資料」だけを根拠に、「質問」に日本語で答えてください。

        - 資料に書かれていないことを、推測や一般的な知識で補わないでください。
        - 根拠にした資料の番号を、その内容を書いた文の末尾に [1] や [2][5] の形で示してください。資料にない番号は使わないでください。
        - 候補者に触れるときは、候補者コードと氏名を書いてください。
        - 資料は質問に近い順に機械的に選んだもので、質問と関係のない資料も含まれます。関係のない資料は使わないでください。
        - 資料から答えがわからないときは「資料からはわかりません」とだけ答えてください。
        TEXT;

    /**
     * 「RAGなし比較」（要件定義書 第5章）で使う system。資料を渡さず、質問だけを送る。
     * 資料ありの回答と並べて「検索で資料を渡すと何が変わるか」を見比べるためのもの
     */
    public const SYSTEM_WITHOUT_RAG = <<<'TEXT'
        あなたは採用担当者を手伝うアシスタントです。ユーザーの質問に日本語で答えてください。
        TEXT;

    public function __construct(private readonly int $chunksPerCandidate)
    {
        if ($chunksPerCandidate < 1) {
            throw new InvalidArgumentException("候補者あたりのチャンク数は1以上にすること: {$chunksPerCandidate}");
        }
    }

    /**
     * @param  RankResult  $ranking  候補者ごとに集約した検索結果（上位 top_k 名、スコア順）
     * @param  array<int, string>  $candidateLabels  候補者 ID => 表示名（例: "C001 山田 太郎"）。ない ID は「候補者 id=…」と書く
     */
    public function build(string $question, RankResult $ranking, array $candidateLabels = []): Prompt
    {
        $sources = [];
        $blocks = [];

        foreach ($ranking->groups as $group) {
            $label = $candidateLabels[$group->key] ?? "候補者 id={$group->key}";
            $lines = ["## 候補者 {$label}"];

            // チャンクは Ranker が類似度の高い順に並べている
            foreach (array_slice($group->chunks, 0, $this->chunksPerCandidate) as $chunk) {
                $number = count($sources) + 1;
                $sources[] = new PromptSource($number, $group->key, $chunk);
                $lines[] = sprintf('[%d] (%s) %s', $number, self::sourceLabel($chunk), trim($chunk->content));
            }

            $blocks[] = implode("\n", $lines);
        }

        $user = "# 資料\n\n"
            .($blocks === [] ? '（該当する資料はありません）' : implode("\n\n", $blocks))
            ."\n\n# 質問\n\n".trim($question);

        return new Prompt(self::SYSTEM, $user, $sources);
    }

    /** 「職務経歴書・職務経歴」のような、文書種別と見出しのラベル */
    public static function sourceLabel(RetrievedChunk $chunk): string
    {
        $type = Document::TYPE_LABELS[$chunk->documentType] ?? $chunk->documentType;

        return $chunk->section === null ? $type : "{$type}・{$chunk->section}";
    }
}
