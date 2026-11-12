<?php

namespace App\Services\Rag;

/**
 * 組み立てたプロンプト。Claude に送る system / user と、資料番号の対応表を持つ。
 */
final readonly class Prompt
{
    /**
     * @param  list<PromptSource>  $sources  資料番号の順（[1] が先頭）
     */
    public function __construct(
        public string $system,
        public string $user,
        public array $sources,
    ) {}

    /** queries.prompt に入れる全文（system と user をまとめて1つの文字列にする） */
    public function fullText(): string
    {
        return "[system]\n{$this->system}\n\n[user]\n{$this->user}";
    }

    /**
     * 回答に出てきた資料番号（[1] や [2][5]）を、出てきた順に重複なしで返す。
     *
     * @return list<int>
     */
    public static function citedNumbers(string $answer): array
    {
        preg_match_all('/\[(\d+)\]/', $answer, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** 番号から資料を引く。ない番号は null */
    public function source(int $number): ?PromptSource
    {
        return $this->sources[$number - 1] ?? null;
    }
}
