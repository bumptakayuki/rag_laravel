<?php

namespace App\Services\Rag;

use App\Models\Document;
use InvalidArgumentException;

/**
 * 求人票から候補者を探す（求人マッチング。要件定義書 第4.2章）。
 *
 * 質問文の代わりに求人票の一部（必須条件など）を使い、あとは候補者検索と同じ
 * 「チャンク検索 → 下限で除外 → 候補者ごとに集約」を行う。
 * 長い文章を1本のベクトルにすると内容が平均化されるので、どの部分を使うかを選べるようにしている（第5章）。
 */
class JobMatcher
{
    /** 必須条件のみ */
    public const SOURCE_REQUIRED = 'required';

    /** 必須条件 + 歓迎条件 */
    public const SOURCE_REQUIRED_PREFERRED = 'required_preferred';

    /** 求人票の全文 */
    public const SOURCE_FULL = 'full';

    /**
     * 検索に使う見出し（求人票の「## 見出し」= チャンクの metadata.section と同じ名前）。
     * full は見出しで選ばず全文を使う
     */
    public const SOURCE_SECTIONS = [
        self::SOURCE_REQUIRED => ['必須条件'],
        self::SOURCE_REQUIRED_PREFERRED => ['必須条件', '歓迎条件'],
        self::SOURCE_FULL => null,
    ];

    public function __construct(private readonly Retriever $retriever) {}

    public function match(Document $job, string $source, SearchOptions $options): JobMatchResult
    {
        $text = $this->sourceText($job, $source);
        $chunks = $this->retriever->retrieve($text, Retriever::SCOPE_CANDIDATE, $options->chunkLimit, $options->documentType);

        return new JobMatchResult($text, $options->candidateRanker()->rank($chunks));
    }

    /**
     * 求人票から検索に使う部分を取り出す。
     *
     * 見出しの名前はチャンクの metadata.section と同じだが、本文はチャンクではなく文書から取る。
     * チャンクは前のチャンクと重なり（overlap）を持つので、つなげると同じ文が2回入るため。
     */
    public function sourceText(Document $job, string $source): string
    {
        if ($job->type !== Document::TYPE_JOB) {
            throw new InvalidArgumentException("求人票ではない文書: id={$job->id}（{$job->type}）");
        }
        if (! array_key_exists($source, self::SOURCE_SECTIONS)) {
            throw new InvalidArgumentException(sprintf('検索に使う部分が不正: %s（%s のどれか）', $source, implode(' / ', array_keys(self::SOURCE_SECTIONS))));
        }

        $names = self::SOURCE_SECTIONS[$source];
        if ($names === null) {
            return trim($job->content);
        }

        $sections = self::sections($job->content);
        $parts = [];
        foreach ($names as $name) {
            if (! isset($sections[$name])) {
                throw new InvalidArgumentException("求人票に見出し「{$name}」がない: id={$job->id}");
            }
            // 見出しも入れる（「必須条件」という語自体も文脈になる）
            $parts[] = "{$name}\n{$sections[$name]}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Markdown を「見出し名 => 本文」に分ける。見出しは # 〜 ######（Chunker と同じ規則）。
     * 求人票一覧の「主な必須スキル」でも使う
     *
     * @return array<string, string>
     */
    public static function sections(string $markdown): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/u', $markdown) as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/u', $line, $m)) {
                $current = trim($m[1]);
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return array_map('trim', $sections);
    }
}
