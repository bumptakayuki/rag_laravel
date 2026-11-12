<?php

namespace App\Services\Rag\Samples;

use App\Services\Rag\ClaudeClient;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * ダミーデータ（架空の候補者の履歴書・職務経歴書と求人票）を Claude で生成して、ファイルに書き出す。
 *
 * - 内容は SampleRoster の設定に従わせる。Claude には各見出しの本文だけを JSON で返させ、
 *   見出し・氏名の行はこちらで組み立てる（見出しの抜けや氏名の書き間違いを起こさないため）
 * - 生成した文章は検査する（連絡先・住所らしきもの、コードブロック、見出し、使ってはいけない語）。
 *   違反があれば理由を添えて作り直させる（最大 MAX_ATTEMPTS 回）
 * - 既にあるファイルは作り直さない（--force のときだけ作り直す）。API の費用を毎回かけないため
 *
 * 出力の配置は Ingestor が読む形と同じ:
 *   {dir}/candidates/{コード}/rirekisho.md, shokumu.md、{dir}/jobs/{slug}.md、評価データは $evalPath
 */
class SampleGenerator
{
    private const MAX_ATTEMPTS = 3;

    /** 履歴書の見出しと、Claude に返させる JSON のキー */
    public const RESUME_SECTIONS = [
        'education' => '学歴',
        'qualifications' => '資格',
        'languages' => '語学',
        'motivation' => '志望動機',
    ];

    /** 職務経歴書の見出しと JSON のキー */
    public const CAREER_SECTIONS = [
        'summary' => '職務要約',
        'career' => '職務経歴',
        'skills' => 'スキル',
        'self_pr' => '自己PR',
    ];

    /** 求人票の見出しと JSON のキー */
    public const JOB_SECTIONS = [
        'overview' => '募集概要',
        'duties' => '業務内容',
        'required' => '必須条件',
        'preferred' => '歓迎条件',
        'environment' => '開発環境・働き方',
    ];

    private const SYSTEM_PROMPT = <<<'TXT'
        あなたは、RAG（検索拡張生成）の学習用アプリで使う日本語のダミー文書を書く担当です。
        書く文書はすべて架空です。次のルールを必ず守ってください。

        - 実在の人物・企業・学校の名前を使わない。会社名は「株式会社サンプル〇〇」「〇〇サンプル株式会社」、
          学校名は「サンプル大学」「〇〇サンプル高校」のように、必ず「サンプル」を含む架空の名前にする
        - 住所・電話番号・メールアドレス・URL・生年月日・年齢は書かない
        - 指示された事実（経験・スキル・熟練度）を正確に反映する。指示にない技術名・製品名・資格を新たに足さない
          （Word・Excel・Slack のような一般的な業務ツールは書いてよい）
        - 熟練度の違い（主力として長く使った / 一部だけ触った / 研修で学んだだけ）が文章から読み取れるように書く
        - 各項目の本文は、空行で区切った段落と「- 」で始まる箇条書きで書く。Markdown の見出し（#）・表・コードブロックは使わない
        - 職務経歴は会社ごとに「【株式会社サンプル〇〇】2018年4月〜2022年3月」のような1行から始める
        - 自然な日本語のビジネス文書として書く
        TXT;

    public function __construct(
        private readonly ClaudeClient $claude,
    ) {}

    /**
     * @param  string  $dir  出力先（storage/app/samples）
     * @param  string  $evalPath  評価データの出力先（storage/app/eval.json）
     * @param  bool  $force  true なら既存ファイルも作り直す
     * @param  Closure(string): void|null  $log  進み具合の表示（コマンドから渡す）
     * @return array{generated: list<string>, skipped: list<string>}
     */
    public function generate(string $dir, string $evalPath, bool $force = false, ?Closure $log = null): array
    {
        $log ??= static function (string $message): void {};
        $generated = [];
        $skipped = [];

        foreach (SampleRoster::candidates() as $candidate) {
            $base = "{$dir}/candidates/{$candidate['code']}";
            $label = "candidates/{$candidate['code']}";
            if (! $force && is_file("{$base}/rirekisho.md") && is_file("{$base}/shokumu.md")) {
                $skipped[] = $label;

                continue;
            }

            $log("生成中: {$label}（{$candidate['name']}・{$candidate['role']}）");
            [$resume, $career] = $this->candidateDocuments($candidate);
            File::ensureDirectoryExists($base);
            File::put("{$base}/rirekisho.md", $resume);
            File::put("{$base}/shokumu.md", $career);
            $generated[] = $label;
        }

        foreach (SampleRoster::jobs() as $job) {
            $file = "{$dir}/jobs/{$job['slug']}.md";
            $label = "jobs/{$job['slug']}.md";
            if (! $force && is_file($file)) {
                $skipped[] = $label;

                continue;
            }

            $log("生成中: {$label}（{$job['title']}）");
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $this->jobDocument($job));
            $generated[] = $label;
        }

        // 評価データは設計図から決まる（API を使わない）ので毎回書き出す
        File::ensureDirectoryExists(dirname($evalPath));
        File::put($evalPath, json_encode(
            ['k' => 5, 'questions' => SampleRoster::evalQuestions()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )."\n");

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * 候補者1名分（履歴書と職務経歴書）を1回の呼び出しで作る。2つの文書の内容を食い違わせないため。
     *
     * @param  array{code: string, name: string, role: string, years: int, facts: list<string>}  $candidate
     * @return array{0: string, 1: string} [履歴書の Markdown, 職務経歴書の Markdown]
     */
    private function candidateDocuments(array $candidate): array
    {
        $keys = array_keys(self::RESUME_SECTIONS + self::CAREER_SECTIONS);
        $forbidden = SampleRoster::forbiddenWordsFor($candidate['code']);

        $prompt = implode("\n", [
            '次の架空の候補者の、履歴書と職務経歴書の各項目の本文を書いてください。',
            '',
            "職種: {$candidate['role']}",
            "社会人経験: {$candidate['years']}年",
            '必ず反映する事実:',
            ...array_map(fn (string $fact) => "- {$fact}", $candidate['facts']),
            '',
            '項目（JSON のキー → 見出し）:',
            ...$this->describeSections(self::RESUME_SECTIONS + self::CAREER_SECTIONS),
            '',
            '分量の目安: 履歴書は全体で 600〜1000 文字、職務経歴書は全体で 1200〜2000 文字。',
            '氏名・見出しはこちらで付けるので、本文には書かないでください。',
            ...($forbidden === [] ? [] : ['', '次の語は使わないでください（言い換えも含めて、その技術・業務の経験があるように読める書き方もしない）: '.implode('、', $forbidden)]),
        ]);

        $json = $this->generateChecked($prompt, $keys, $forbidden);

        $header = "氏名：{$candidate['name']}\n希望職種：{$candidate['role']}\n社会人経験：{$candidate['years']}年";

        return [
            self::markdown('履歴書', $header, self::RESUME_SECTIONS, $json),
            self::markdown('職務経歴書', $header, self::CAREER_SECTIONS, $json),
        ];
    }

    /**
     * @param  array{slug: string, title: string, facts: list<string>}  $job
     */
    private function jobDocument(array $job): string
    {
        $keys = ['company', ...array_keys(self::JOB_SECTIONS)];

        $prompt = implode("\n", [
            '次の架空の求人票の各項目の本文を書いてください。',
            '',
            "募集職種: {$job['title']}",
            '必ず反映する事実:',
            ...array_map(fn (string $fact) => "- {$fact}", $job['facts']),
            '',
            '項目（JSON のキー → 見出し）:',
            '- company: 募集する会社の架空の名前（「株式会社サンプル〇〇」の形、1行）',
            ...$this->describeSections(self::JOB_SECTIONS),
            '',
            '分量の目安: 全体で 800〜1400 文字。給与の金額は書かないでください。',
        ]);

        $json = $this->generateChecked($prompt, $keys, []);
        $header = '会社名：'.trim($json['company'])."\n募集職種：{$job['title']}";

        return self::markdown("求人票：{$job['title']}", $header, self::JOB_SECTIONS, $json);
    }

    /**
     * Claude に JSON を作らせ、検査に通るまで作り直させる。
     *
     * @param  list<string>  $keys
     * @param  list<string>  $forbidden
     * @return array<string, string>
     */
    private function generateChecked(string $prompt, array $keys, array $forbidden): array
    {
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys($keys, ['type' => 'string']),
            'required' => $keys,
            'additionalProperties' => false,
        ];

        $feedback = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $json = $this->claude->generateJson(self::SYSTEM_PROMPT, $prompt.$feedback, $schema);
            $problems = self::problems($json, $keys, $forbidden);
            if ($problems === []) {
                return $json;
            }
            $feedback = "\n\n前回の出力には次の問題がありました。直して書き直してください:\n- ".implode("\n- ", $problems);
        }

        throw new RuntimeException('生成した文章が '.self::MAX_ATTEMPTS.' 回とも検査に通らなかった: '.implode(' / ', $problems));
    }

    /**
     * 生成した文章の検査。問題の説明を返す（問題が無ければ空配列）。
     *
     * @param  array<string, mixed>  $json
     * @param  list<string>  $keys
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    public static function problems(array $json, array $keys, array $forbidden): array
    {
        $problems = [];
        foreach ($keys as $key) {
            if (! is_string($json[$key] ?? null) || trim($json[$key]) === '') {
                $problems[] = "{$key} が空";
            }
        }
        $text = implode("\n", array_filter(array_map(fn ($key) => $json[$key] ?? null, $keys), 'is_string'));

        // 個人情報らしきもの（架空データでも入れない）
        $patterns = [
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => 'メールアドレスらしき文字列がある',
            '/(?<!\d)0\d{1,3}-\d{2,4}-\d{3,4}(?!\d)/' => '電話番号らしき文字列がある',
            // 前後に数字が続くもの（「2018-2022」など年の範囲）は除く
            '/〒|(?<!\d)\d{3}-\d{4}(?!\d)/u' => '郵便番号らしき文字列がある',
            '#https?://#' => 'URL がある',
            '/生年月日|住所|満\d+歳/u' => '生年月日・住所・年齢の記載がある',
        ];
        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $text) === 1) {
                $problems[] = $message;
            }
        }

        // Chunker はコードブロック内の "# ..." も見出し扱いするため、コードブロックと見出しを入れない
        if (str_contains($text, '```')) {
            $problems[] = 'コードブロック（```）がある';
        }
        if (preg_match('/^\s*#{1,6}\s/mu', $text) === 1) {
            $problems[] = 'Markdown の見出し（#）がある';
        }

        foreach ($forbidden as $word) {
            if (self::containsWord($text, $word)) {
                $problems[] = "使ってはいけない語「{$word}」がある";
            }
        }

        return $problems;
    }

    /** 英数字の語は前後が英字でないときだけ一致とみなす（"EKS" が "weeks" に一致しないように）。大文字小文字は区別しない */
    private static function containsWord(string $text, string $word): bool
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $word) === 1) {
            return preg_match('/(?<![A-Za-z])'.preg_quote($word, '/').'(?![A-Za-z])/i', $text) === 1;
        }

        return mb_stripos($text, $word) !== false;
    }

    /**
     * @param  array<string, string>  $sections
     * @return list<string>
     */
    private function describeSections(array $sections): array
    {
        return array_map(fn (string $key, string $heading) => "- {$key}: {$heading}", array_keys($sections), $sections);
    }

    /**
     * 見出し付きの Markdown に組み立てる。
     *
     * @param  array<string, string>  $sections  JSON のキー → 見出し
     * @param  array<string, string>  $json
     */
    private static function markdown(string $title, string $header, array $sections, array $json): string
    {
        $parts = ["# {$title}", $header];
        foreach ($sections as $key => $heading) {
            $parts[] = "## {$heading}";
            $parts[] = trim(str_replace(["\r\n", "\r"], "\n", $json[$key]));
        }

        return implode("\n\n", $parts)."\n";
    }
}
