<?php

namespace App\Services\Rag;

/**
 * 文書の全文を「見出し」と「チャンクの本文」の並びで組み立てる。外部依存なしの純粋なクラス。
 * 候補者詳細（ヒット箇所のハイライト）と求人票詳細（見出しごとの chunk_index）で使う。
 *
 * チャンクの本文は全文の部分文字列とは限らない（Chunker が段落を "\n\n" でつなぎ、先頭に前のチャンクの
 * 末尾を重ねるため）。そこで全文の中から位置を探すのではなく、
 *   - 見出しは全文から順に取り出し（Chunker と同じ規則）、
 *   - その見出しの下に、section が同じチャンクを chunk_index の順に置き、
 *   - 2つ目以降のチャンクは、先頭の重なり（前のチャンクの末尾と同じ部分）を取り除いて本文にする。
 * こうするとチャンク同士が重ならずに並び、1チャンク = 1ブロックとしてハイライトやリンク先にできる。
 *
 * 見出しだけで本文のない節（「## 職務経歴」の下がすぐ「### 会社名」など）も、見出しとして出す。
 */
final class DocumentOutline
{
    /** Chunker と同じ ATX 見出しの規則 */
    private const HEADING = '/^(#{1,6})\s+(.+?)(?:\s+#+)?\s*$/u';

    /**
     * @param  list<array{id: int, chunk_index: int, section: string|null, content: string, overlap: int}>  $chunks
     *                                                                                                               overlap は取り込み時の重なり文字数（chunks.metadata.chunk_overlap）
     * @return list<array{kind: 'heading', level: int, text: string}|array{kind: 'chunk', id: int, chunk_index: int, section: string|null, body: string}>
     */
    public static function build(string $content, array $chunks): array
    {
        usort($chunks, fn (array $a, array $b) => $a['chunk_index'] <=> $b['chunk_index']);

        $blocks = [];
        $position = 0;
        $previous = null;

        // 見出しの下に、section が同じチャンクを続けて置く
        $takeChunks = function (?string $section) use (&$blocks, &$position, &$previous, $chunks): void {
            while (isset($chunks[$position]) && $chunks[$position]['section'] === $section) {
                $chunk = $chunks[$position];
                $blocks[] = [
                    'kind' => 'chunk',
                    'id' => $chunk['id'],
                    'chunk_index' => $chunk['chunk_index'],
                    'section' => $section,
                    'body' => self::withoutOverlap($chunk, $previous),
                ];
                $previous = $chunk;
                $position++;
            }
        };

        // 最初の見出しより前の本文（section = null）
        $takeChunks(null);

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $content)) as $line) {
            if (preg_match(self::HEADING, $line, $m) !== 1) {
                continue;
            }
            $text = trim($m[2]);
            $blocks[] = ['kind' => 'heading', 'level' => strlen($m[1]), 'text' => $text];
            $previous = null; // 見出しが変わると重なりはない（Chunker はセクションをまたいで重ねない）
            $takeChunks($text);
        }

        // 見出しと対応しなかったチャンク（取り込み後に本文を変えた等）は、最後にそのまま並べる
        while (isset($chunks[$position])) {
            $chunk = $chunks[$position];
            $blocks[] = ['kind' => 'chunk', 'id' => $chunk['id'], 'chunk_index' => $chunk['chunk_index'], 'section' => $chunk['section'], 'body' => $chunk['content']];
            $position++;
        }

        return $blocks;
    }

    /**
     * 先頭の重なりを取り除いた本文。同じ節の前のチャンクの末尾と一致したときだけ取り除く。
     *
     * @param  array{content: string, overlap: int}  $chunk
     * @param  array{content: string}|null  $previous  同じ節の1つ前のチャンク（節の最初なら null）
     */
    private static function withoutOverlap(array $chunk, ?array $previous): string
    {
        if ($previous === null || $chunk['overlap'] <= 0) {
            return $chunk['content'];
        }

        // Chunker は mb_substr($previous, -overlap) を重ねる（前のチャンクが短ければ全体）
        $length = min($chunk['overlap'], mb_strlen($previous['content']));
        $head = mb_substr($chunk['content'], 0, $length);

        return $head === mb_substr($previous['content'], -$length)
            ? mb_substr($chunk['content'], $length)
            : $chunk['content'];
    }
}
