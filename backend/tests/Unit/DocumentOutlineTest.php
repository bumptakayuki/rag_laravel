<?php

namespace Tests\Unit;

use App\Services\Rag\Chunker;
use App\Services\Rag\DocumentOutline;
use PHPUnit\Framework\TestCase;

/**
 * DocumentOutline（全文を見出し + 重なりを除いたチャンク本文で組み立てる）のテスト。DB を使わない。
 */
class DocumentOutlineTest extends TestCase
{
    /** Chunker の出力を DocumentOutline の入力の形にする */
    private static function chunksFrom(array $split, int $overlap): array
    {
        return array_map(fn (array $c, int $i) => [
            'id' => 100 + $i,
            'chunk_index' => $i,
            'section' => $c['section'],
            'content' => $c['content'],
            'overlap' => $overlap,
        ], $split, array_keys($split));
    }

    public function test_見出しと_その下のチャンクが文書の順に並ぶ(): void
    {
        $content = "# 職務経歴書\n\n## 職務要約\n\n要約の本文。\n\n## 職務経歴\n\n### 株式会社サンプル\n\n開発を担当。\n\n## スキル\n\nPHP";
        $chunks = [
            ['id' => 3, 'chunk_index' => 2, 'section' => 'スキル', 'content' => 'PHP', 'overlap' => 0],
            ['id' => 1, 'chunk_index' => 0, 'section' => '職務要約', 'content' => '要約の本文。', 'overlap' => 0],
            ['id' => 2, 'chunk_index' => 1, 'section' => '株式会社サンプル', 'content' => '開発を担当。', 'overlap' => 0],
        ];

        $this->assertSame([
            ['kind' => 'heading', 'level' => 1, 'text' => '職務経歴書'],
            ['kind' => 'heading', 'level' => 2, 'text' => '職務要約'],
            ['kind' => 'chunk', 'id' => 1, 'chunk_index' => 0, 'section' => '職務要約', 'body' => '要約の本文。'],
            // 本文のない見出しも出す
            ['kind' => 'heading', 'level' => 2, 'text' => '職務経歴'],
            ['kind' => 'heading', 'level' => 3, 'text' => '株式会社サンプル'],
            ['kind' => 'chunk', 'id' => 2, 'chunk_index' => 1, 'section' => '株式会社サンプル', 'body' => '開発を担当。'],
            ['kind' => 'heading', 'level' => 2, 'text' => 'スキル'],
            ['kind' => 'chunk', 'id' => 3, 'chunk_index' => 2, 'section' => 'スキル', 'body' => 'PHP'],
        ], DocumentOutline::build($content, $chunks));
    }

    public function test_同じ節の2つ目以降のチャンクは先頭の重なりを取り除く(): void
    {
        $content = "## 自己PR\n\nあいうえおかきくけこ\n\nさしすせそ";
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => '自己PR', 'content' => 'あいうえおかきくけこ', 'overlap' => 3],
            // 先頭の「くけこ」は前のチャンクの末尾3文字
            ['id' => 2, 'chunk_index' => 1, 'section' => '自己PR', 'content' => 'くけこさしすせそ', 'overlap' => 3],
        ];

        $blocks = DocumentOutline::build($content, $chunks);

        $this->assertSame('あいうえおかきくけこ', $blocks[1]['body']);
        $this->assertSame('さしすせそ', $blocks[2]['body']);
    }

    public function test_前のチャンクが重なりより短ければ全体を重なりとみなす(): void
    {
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => 'A', 'content' => 'あい', 'overlap' => 5],
            ['id' => 2, 'chunk_index' => 1, 'section' => 'A', 'content' => 'あいうえお', 'overlap' => 5],
        ];

        $this->assertSame('うえお', DocumentOutline::build("## A\n\nあい\n\nうえお", $chunks)[2]['body']);
    }

    public function test_先頭が前のチャンクの末尾と一致しなければそのまま出す(): void
    {
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => 'A', 'content' => 'あいうえお', 'overlap' => 2],
            ['id' => 2, 'chunk_index' => 1, 'section' => 'A', 'content' => 'かきくけこ', 'overlap' => 2],
        ];

        $this->assertSame('かきくけこ', DocumentOutline::build("## A\n\nx", $chunks)[2]['body']);
    }

    public function test_節が変わると重なりを取り除かない(): void
    {
        // 見出し B の最初のチャンクが、偶然 A の末尾と同じ文字で始まっても削らない（Chunker は節をまたいで重ねない）
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => 'A', 'content' => 'あいう', 'overlap' => 1],
            ['id' => 2, 'chunk_index' => 1, 'section' => 'B', 'content' => 'うえお', 'overlap' => 1],
        ];

        $this->assertSame('うえお', DocumentOutline::build("## A\n\nあいう\n\n## B\n\nうえお", $chunks)[3]['body']);
    }

    public function test_最初の見出しより前の本文と_見出しに対応しないチャンクも出す(): void
    {
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => null, 'content' => '前書き', 'overlap' => 0],
            ['id' => 2, 'chunk_index' => 1, 'section' => 'A', 'content' => '本文', 'overlap' => 0],
            ['id' => 3, 'chunk_index' => 2, 'section' => '消えた見出し', 'content' => '残り', 'overlap' => 0],
        ];

        $blocks = DocumentOutline::build("前書き\n\n## A\n\n本文", $chunks);

        $this->assertSame(['chunk', 'heading', 'chunk', 'chunk'], array_column($blocks, 'kind'));
        $this->assertSame('前書き', $blocks[0]['body']);
        $this->assertSame('残り', $blocks[3]['body']);
    }

    public function test_同じ名前の見出しが2回あっても文書の順に対応させる(): void
    {
        $content = "## 会社A\n\n### 業務内容\n\n設計\n\n## 会社B\n\n### 業務内容\n\n運用";
        $chunks = [
            ['id' => 1, 'chunk_index' => 0, 'section' => '業務内容', 'content' => '設計', 'overlap' => 0],
            ['id' => 2, 'chunk_index' => 1, 'section' => '業務内容', 'content' => '運用', 'overlap' => 0],
        ];

        $blocks = DocumentOutline::build($content, $chunks);

        // 1つ目の「業務内容」の下に同じ節名のチャンクが続けて入るが、本文は順番どおりに全部出る
        $this->assertSame(['設計', '運用'], array_values(array_column(array_filter($blocks, fn ($b) => $b['kind'] === 'chunk'), 'body')));
    }

    public function test_chunker_で分けた文書を組み立てると_本文が重複も欠けもなく元に戻る(): void
    {
        // 長い段落（文字数で切られる）と短い段落（結合される）を混ぜる
        $long = str_repeat('PHP と Laravel で業務システムを開発した。', 12);
        $content = "# 職務経歴書\n\n## 職務要約\n\n{$long}\n\n短い段落。\n\nもう一つ短い段落。\n\n## スキル\n\n- PHP\n- Laravel\n\n## 自己PR\n\n".str_repeat('チームで設計を進めた。', 20);

        $overlap = 30;
        $split = (new Chunker(size: 120, overlap: $overlap, minSize: 40))->split($content);
        $this->assertGreaterThan(5, count($split));

        $blocks = DocumentOutline::build($content, self::chunksFrom($split, $overlap));

        // 見出し行を除いた全文と、組み立てた本文をつないだものが（空白を除いて）一致する
        $strip = fn (string $s) => preg_replace('/\s+/u', '', $s);
        $original = $strip(preg_replace('/^#{1,6}\s+.+$/mu', '', $content));
        $rebuilt = $strip(implode('', array_column(array_filter($blocks, fn ($b) => $b['kind'] === 'chunk'), 'body')));
        $this->assertSame($original, $rebuilt);

        $this->assertSame(['職務経歴書', '職務要約', 'スキル', '自己PR'], array_values(array_column(array_filter($blocks, fn ($b) => $b['kind'] === 'heading'), 'text')));
    }
}
