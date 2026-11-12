<?php

namespace App\Services\Rag;

use InvalidArgumentException;

/**
 * テキスト（Markdown）を検索用の断片（チャンク）に分ける。外部依存なしの純粋なクラス。
 *
 * 分け方（要件定義書 第4.1章）:
 *   1. Markdown の見出し（# 〜 ######）でセクションに分ける。見出し名は各チャンクの section になる。
 *      見出し行そのものはチャンク本文に入れない（画面では「職務経歴書・スキル」のように section を表示する）
 *   2. セクション内は、空行区切りの段落を基本単位にする
 *   3. 上限を超える段落は、句点（。！？）や改行の位置で切る（見つからなければ文字数で切る）
 *   4. min_size 未満の小さな段落は、上限に収まる範囲で次の段落と結合する
 *   5. セクション内の2つ目以降のチャンクは、先頭に前のチャンクの末尾 overlap 文字を重ねる。
 *      文の途中で切れても、前後どちらのチャンクからも文脈を拾えるようにするため。
 *      見出しが変わると話題も変わるので、セクションをまたいでは重ねない
 *
 * 長さはすべて mb_strlen（文字数）で数える。日本語でもマルチバイト文字の途中で切らない。
 * 本文の上限は size - overlap 文字で、重なりを足しても1チャンクは必ず size 文字以内に収まる。
 */
final class Chunker
{
    /** 長い段落を切る位置の候補（この文字の直後で切る） */
    private const BREAK_CHARS = ['。', '！', '？', '!', '?', "\n"];

    /** 重なりを除いた本文の上限文字数 */
    private readonly int $bodyLimit;

    public function __construct(
        private readonly int $size = 600,
        private readonly int $overlap = 100,
        private readonly int $minSize = 150,
    ) {
        if ($size <= 0) {
            throw new InvalidArgumentException("size は1以上にすること（指定: {$size}）");
        }
        if ($overlap < 0 || $overlap >= $size) {
            throw new InvalidArgumentException("overlap は0以上かつ size 未満にすること（指定: size={$size}, overlap={$overlap}）");
        }
        if ($minSize < 0) {
            throw new InvalidArgumentException("min_size は0以上にすること（指定: {$minSize}）");
        }

        $this->bodyLimit = $size - $overlap;
    }

    /**
     * @return list<array{content: string, section: string|null}> 文書内の順番どおりに並んだチャンク
     */
    public function split(string $text): array
    {
        $chunks = [];

        foreach ($this->sections($text) as [$section, $paragraphs]) {
            $previous = null;

            foreach ($this->bodies($paragraphs) as $body) {
                // セクション内の2つ目以降は、前のチャンク末尾を重ねる
                $content = ($previous === null || $this->overlap === 0)
                    ? $body
                    : mb_substr($previous, -$this->overlap).$body;

                $chunks[] = ['content' => $content, 'section' => $section];
                $previous = $content;
            }
        }

        return $chunks;
    }

    /**
     * 見出しでセクションに分け、セクションごとに段落の一覧を返す。
     *
     * @return list<array{0: string|null, 1: list<string>}> [見出し名（最初の見出しより前は null）, 段落の一覧]
     */
    private function sections(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $sections = [];
        $section = null;
        $paragraphs = [];
        $lines = [];

        $flushParagraph = function () use (&$lines, &$paragraphs): void {
            if ($lines !== []) {
                $paragraphs[] = implode("\n", $lines);
                $lines = [];
            }
        };
        $flushSection = function () use (&$sections, &$section, &$paragraphs): void {
            if ($paragraphs !== []) {
                $sections[] = [$section, $paragraphs];
                $paragraphs = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            // ATX 見出し（"## スキル"）。"#" の後に空白が必要（"#タグ" や "C#" は見出しではない）
            if (preg_match('/^#{1,6}\s+(.+?)(?:\s+#+)?\s*$/u', $line, $m) === 1) {
                $flushParagraph();
                $flushSection();
                $section = trim($m[1]);

                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();

                continue;
            }

            $lines[] = rtrim($line);
        }

        $flushParagraph();
        $flushSection();

        return $sections;
    }

    /**
     * 段落の一覧から、重なりを付ける前の本文（各 bodyLimit 文字以内）を作る。
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function bodies(array $paragraphs): array
    {
        $pieces = [];
        foreach ($paragraphs as $paragraph) {
            array_push($pieces, ...$this->splitLong($paragraph));
        }

        $bodies = [];
        $buffer = null;

        foreach ($pieces as $piece) {
            if ($buffer === null) {
                $buffer = $piece;

                continue;
            }

            // 小さすぎる段落は、上限に収まるなら次の段落と結合する
            $joined = $buffer."\n\n".$piece;
            if (mb_strlen($buffer) < $this->minSize && mb_strlen($joined) <= $this->bodyLimit) {
                $buffer = $joined;

                continue;
            }

            $bodies[] = $buffer;
            $buffer = $piece;
        }

        if ($buffer !== null) {
            $bodies[] = $buffer;
        }

        return $bodies;
    }

    /**
     * 上限を超える段落を bodyLimit 文字以内に切り分ける。
     *
     * @return list<string>
     */
    private function splitLong(string $paragraph): array
    {
        $pieces = [];

        while (mb_strlen($paragraph) > $this->bodyLimit) {
            $cut = $this->cutPosition(mb_substr($paragraph, 0, $this->bodyLimit));

            $piece = rtrim(mb_substr($paragraph, 0, $cut));
            if ($piece !== '') {
                $pieces[] = $piece;
            }
            $paragraph = ltrim(mb_substr($paragraph, $cut));
        }

        if ($paragraph !== '') {
            $pieces[] = $paragraph;
        }

        return $pieces;
    }

    /**
     * 上限ちょうどの窓の中で、最後の句点・改行の直後を切る位置にする。
     * 前半にしか見つからない場合は短すぎるチャンクになるので、上限の位置で切る。
     */
    private function cutPosition(string $window): int
    {
        $best = -1;
        foreach (self::BREAK_CHARS as $char) {
            $pos = mb_strrpos($window, $char);
            if ($pos !== false && $pos > $best) {
                $best = $pos;
            }
        }

        $cut = $best + 1;

        return ($best >= 0 && $cut >= intdiv($this->bodyLimit, 2)) ? $cut : $this->bodyLimit;
    }
}
