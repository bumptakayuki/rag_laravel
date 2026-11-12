<?php

namespace Tests\Unit;

use App\Services\Rag\Chunker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chunker は DB も設定も使わない純粋なクラスなので、Laravel を起動しない PHPUnit の TestCase で試す。
 * 数字を追いやすいように、多くのテストは小さな size / overlap で作る。
 */
class ChunkerTest extends TestCase
{
    // ---- 基本 ----

    public function test_empty_or_heading_only_text_has_no_chunks(): void
    {
        $chunker = new Chunker;

        $this->assertSame([], $chunker->split(''));
        $this->assertSame([], $chunker->split("  \n\n\t\n"));
        $this->assertSame([], $chunker->split("# 履歴書\n\n## 学歴\n"));
    }

    public function test_paragraph_is_the_basic_unit(): void
    {
        $chunker = new Chunker(size: 100, overlap: 0, minSize: 5);

        $chunks = $chunker->split("一つ目の段落です。\n\n二つ目の段落です。");

        $this->assertSame(['一つ目の段落です。', '二つ目の段落です。'], $this->contents($chunks));
    }

    public function test_lines_without_blank_line_stay_in_one_paragraph(): void
    {
        $chunker = new Chunker(size: 100, overlap: 0, minSize: 0);

        $chunks = $chunker->split("1行目\n2行目\n\n次の段落");

        $this->assertSame(["1行目\n2行目", '次の段落'], $this->contents($chunks));
    }

    // ---- 小さな段落の結合 ----

    public function test_small_paragraph_is_merged_with_next(): void
    {
        $chunker = new Chunker(size: 100, overlap: 0, minSize: 10);

        // 「氏名：架空太郎」は7文字で min_size 未満 → 次の段落と結合する
        $chunks = $chunker->split("氏名：架空太郎\n\n志望動機は、自社サービスの開発に関わりたいからです。");

        $this->assertSame(["氏名：架空太郎\n\n志望動機は、自社サービスの開発に関わりたいからです。"], $this->contents($chunks));
    }

    public function test_consecutive_small_paragraphs_are_merged_until_min_size(): void
    {
        $chunker = new Chunker(size: 100, overlap: 0, minSize: 8);

        // 「あ」(1) + 「いい」(2) → 「あ\n\nいい」(5) はまだ小さいので「ううう」とも結合 → 10文字で min 以上
        $chunks = $chunker->split("あ\n\nいい\n\nううう\n\nええええ");

        $this->assertSame(["あ\n\nいい\n\nううう", 'ええええ'], $this->contents($chunks));
    }

    public function test_paragraph_of_exactly_min_size_is_not_merged(): void
    {
        $chunker = new Chunker(size: 100, overlap: 0, minSize: 5);

        // 5文字は min_size 未満ではないので結合しない（4文字なら結合する）
        $this->assertSame(['あいうえお', 'かきく'], $this->contents($chunker->split("あいうえお\n\nかきく")));
        $this->assertSame(["あいうえ\n\nかきく"], $this->contents($chunker->split("あいうえ\n\nかきく")));
    }

    public function test_small_paragraph_is_not_merged_when_result_exceeds_limit(): void
    {
        $chunker = new Chunker(size: 10, overlap: 0, minSize: 5);

        // 「あい」(2) + "\n\n" + 9文字 = 13 > 10 なので結合しない
        $chunks = $chunker->split("あい\n\nかきくけこさしすせ");

        $this->assertSame(['あい', 'かきくけこさしすせ'], $this->contents($chunks));
    }

    // ---- 上限文字数（境界値） ----

    public function test_paragraph_of_exactly_size_is_one_chunk(): void
    {
        $chunker = new Chunker(size: 20, overlap: 0, minSize: 0);

        $chunks = $chunker->split(str_repeat('あ', 20));

        $this->assertCount(1, $chunks);
        $this->assertSame(20, mb_strlen($chunks[0]['content']));
    }

    public function test_paragraph_of_size_plus_one_is_split(): void
    {
        $chunker = new Chunker(size: 20, overlap: 0, minSize: 0);

        $chunks = $chunker->split(str_repeat('あ', 21));

        $this->assertSame([20, 1], $this->lengths($chunks));
    }

    public function test_body_limit_is_size_minus_overlap(): void
    {
        // size 20 / overlap 5 → 本文の上限は15文字
        $chunker = new Chunker(size: 20, overlap: 5, minSize: 0);

        $this->assertCount(1, $chunker->split(str_repeat('あ', 15)));

        $chunks = $chunker->split(str_repeat('あ', 16));
        // 2つ目 = 前の末尾5文字 + 残り1文字
        $this->assertSame([15, 6], $this->lengths($chunks));
    }

    public function test_long_paragraph_is_cut_after_sentence_end(): void
    {
        $chunker = new Chunker(size: 15, overlap: 0, minSize: 0);

        // 上限15文字の窓の中で最後の「。」は11文字目 → その直後で切る
        $chunks = $chunker->split('あいうえおかきくけこ。さしすせそたちつてと');

        $this->assertSame(['あいうえおかきくけこ。', 'さしすせそたちつてと'], $this->contents($chunks));
    }

    public function test_sentence_end_in_first_half_is_ignored(): void
    {
        $chunker = new Chunker(size: 20, overlap: 0, minSize: 0);

        // 「。」が3文字目（上限の半分未満）だと短すぎるので、上限の20文字で切る
        $text = 'あい。'.str_repeat('か', 30);
        $chunks = $chunker->split($text);

        $this->assertSame([20, 13], $this->lengths($chunks));
        $this->assertSame($text, implode('', $this->contents($chunks)));
    }

    public function test_every_chunk_fits_in_size_including_overlap(): void
    {
        $chunker = new Chunker(size: 50, overlap: 10, minSize: 15);

        $text = implode("\n\n", [
            str_repeat('PHPとLaravelで業務システムを開発しました。', 6),
            '短い段落。',
            str_repeat('インフラはAWSを使い、TerraformでECSとRDSを構築しました。', 4),
            '🍣と絵文字を含む行。',
        ]);

        foreach ($chunker->split($text) as $chunk) {
            $this->assertLessThanOrEqual(50, mb_strlen($chunk['content']), $chunk['content']);
        }
    }

    // ---- オーバーラップ ----

    public function test_chunk_starts_with_tail_of_previous_chunk(): void
    {
        $chunker = new Chunker(size: 20, overlap: 5, minSize: 0);

        $chunks = $this->contents($chunker->split(str_repeat('あいうえおかきくけこ', 4)));

        $this->assertGreaterThan(2, count($chunks));
        for ($i = 1; $i < count($chunks); $i++) {
            $this->assertStringStartsWith(mb_substr($chunks[$i - 1], -5), $chunks[$i]);
        }
    }

    public function test_overlap_also_applies_across_paragraphs(): void
    {
        $chunker = new Chunker(size: 30, overlap: 4, minSize: 0);

        $chunks = $chunker->split("前の段落の最後はここまで。\n\n次の段落です。");

        // 2つ目のチャンクの先頭に、前の段落の末尾4文字が付く
        $this->assertSame(['前の段落の最後はここまで。', 'こまで。次の段落です。'], $this->contents($chunks));
    }

    public function test_previous_chunk_shorter_than_overlap_is_prefixed_whole(): void
    {
        $chunker = new Chunker(size: 30, overlap: 10, minSize: 0);

        // 前のチャンク「短い。」は3文字で overlap(10) より短いので、全体がそのまま先頭に付く
        $chunks = $chunker->split("短い。\n\n次の段落。");

        $this->assertSame(['短い。', '短い。次の段落。'], $this->contents($chunks));
    }

    public function test_zero_overlap_keeps_original_text(): void
    {
        $chunker = new Chunker(size: 15, overlap: 0, minSize: 0);
        $text = 'Laravelで社内向けの勤怠管理システムを開発し、PostgreSQLのチューニングも担当した。';

        $chunks = $chunker->split($text);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($text, implode('', $this->contents($chunks)));
    }

    public function test_overlap_does_not_cross_sections(): void
    {
        $chunker = new Chunker(size: 30, overlap: 4, minSize: 0);

        $chunks = $chunker->split("## スキル\nPHP、Laravel、PostgreSQL。\n\n## 自己PR\n粘り強く取り組みます。");

        $this->assertSame(['PHP、Laravel、PostgreSQL。', '粘り強く取り組みます。'], $this->contents($chunks));
    }

    // ---- 見出し → section ----

    public function test_heading_becomes_section_and_is_not_in_content(): void
    {
        $chunker = new Chunker(size: 200, overlap: 0, minSize: 0);

        $chunks = $chunker->split(implode("\n", [
            '# 職務経歴書',
            '## 職務要約',
            'Webエンジニアとして7年の経験があります。',
            '',
            '## スキル',
            '- PHP（Laravel）：5年',
            '- Go：1年',
        ]));

        $this->assertSame([
            ['content' => 'Webエンジニアとして7年の経験があります。', 'section' => '職務要約'],
            ['content' => "- PHP（Laravel）：5年\n- Go：1年", 'section' => 'スキル'],
        ], $chunks);
    }

    public function test_text_before_first_heading_has_null_section(): void
    {
        $chunker = new Chunker(size: 200, overlap: 0, minSize: 0);

        $chunks = $chunker->split("見出しのない前書き\n\n# 履歴書\n氏名：架空花子");

        $this->assertSame([
            ['content' => '見出しのない前書き', 'section' => null],
            ['content' => '氏名：架空花子', 'section' => '履歴書'],
        ], $chunks);
    }

    public function test_small_paragraphs_are_not_merged_across_sections(): void
    {
        $chunker = new Chunker(size: 200, overlap: 0, minSize: 50);

        $chunks = $chunker->split("## 学歴\nサンプル大学 卒業\n\n## 資格\n基本情報技術者");

        $this->assertSame(['学歴', '資格'], array_column($chunks, 'section'));
    }

    #[DataProvider('headingLines')]
    public function test_heading_detection(string $line, ?string $expectedSection): void
    {
        $chunker = new Chunker(size: 200, overlap: 0, minSize: 0);

        $chunks = $chunker->split($line."\n本文");

        if ($expectedSection === null) {
            // 見出しではない → 本文として残る
            $this->assertSame([['content' => $line."\n本文", 'section' => null]], $chunks);
        } else {
            $this->assertSame([['content' => '本文', 'section' => $expectedSection]], $chunks);
        }
    }

    /** @return array<string, array{string, string|null}> */
    public static function headingLines(): array
    {
        return [
            'h1' => ['# 履歴書', '履歴書'],
            'h6' => ['###### 細かい見出し', '細かい見出し'],
            '末尾の空白' => ['## スキル  ', 'スキル'],
            '閉じの#' => ['## スキル ##', 'スキル'],
            'C# で終わる見出し' => ['## C#', 'C#'],
            '#の後に空白がない' => ['#タグ', null],
            '7つ以上の#' => ['####### 七段', null],
            '行頭以外の#' => ['C# の経験 3年', null],
        ];
    }

    // ---- 日本語・入力の揺れ ----

    public function test_japanese_is_counted_by_characters_and_not_broken(): void
    {
        $chunker = new Chunker(size: 7, overlap: 2, minSize: 0);
        $text = '日本語の文章を文字数で正しく分割できることを確かめる';

        $chunks = $chunker->split($text);

        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk['content'], 'UTF-8'), 'マルチバイト文字の途中で切れている');
            $this->assertLessThanOrEqual(7, mb_strlen($chunk['content']));
        }
        // 重なり（先頭2文字）を除いてつなげると元の文章に戻る
        $restored = $chunks[0]['content'];
        for ($i = 1; $i < count($chunks); $i++) {
            $restored .= mb_substr($chunks[$i]['content'], 2);
        }
        $this->assertSame($text, $restored);
    }

    public function test_crlf_line_endings_are_normalized(): void
    {
        $chunker = new Chunker(size: 200, overlap: 0, minSize: 0);

        $chunks = $chunker->split("## 学歴\r\nサンプル大学\r\n\r\n## 資格\r\n普通自動車免許");

        $this->assertSame([
            ['content' => 'サンプル大学', 'section' => '学歴'],
            ['content' => '普通自動車免許', 'section' => '資格'],
        ], $chunks);
    }

    public function test_default_settings_on_resume_like_document(): void
    {
        $chunker = new Chunker; // 初期値 600 / 100 / 150

        $career = str_repeat('株式会社サンプルでECサイトのバックエンドをPHPとLaravelで開発し、決済まわりの改修とテストの整備を担当しました。', 15);
        $chunks = $chunker->split("# 職務経歴書\n\n## 職務要約\n\n要約です。\n\n## 職務経歴\n\n{$career}\n\n## 自己PR\n\n丁寧な設計を心がけています。");

        $this->assertSame('職務要約', $chunks[0]['section']);
        $this->assertSame('自己PR', $chunks[array_key_last($chunks)]['section']);

        $career = array_values(array_filter($chunks, fn ($c) => $c['section'] === '職務経歴'));
        $this->assertGreaterThan(1, count($career));
        foreach ($career as $i => $chunk) {
            $this->assertLessThanOrEqual(600, mb_strlen($chunk['content']));
            if ($i > 0) {
                $this->assertStringStartsWith(mb_substr($career[$i - 1]['content'], -100), $chunk['content']);
            }
        }
    }

    // ---- 設定値の検査 ----

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected(int $size, int $overlap, int $minSize): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Chunker($size, $overlap, $minSize);
    }

    /** @return array<string, array{int, int, int}> */
    public static function invalidSettings(): array
    {
        return [
            'size が0' => [0, 0, 0],
            'overlap が負' => [10, -1, 0],
            'overlap が size と同じ' => [10, 10, 0],
            'min_size が負' => [10, 0, -1],
        ];
    }

    // ---- ヘルパー ----

    /**
     * @param  list<array{content: string, section: string|null}>  $chunks
     * @return list<string>
     */
    private function contents(array $chunks): array
    {
        return array_column($chunks, 'content');
    }

    /**
     * @param  list<array{content: string, section: string|null}>  $chunks
     * @return list<int>
     */
    private function lengths(array $chunks): array
    {
        return array_map(fn ($c) => mb_strlen($c['content']), $chunks);
    }
}
