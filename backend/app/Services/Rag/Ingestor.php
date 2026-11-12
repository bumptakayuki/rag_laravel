<?php

namespace App\Services\Rag;

use App\Models\Candidate;
use App\Models\Document;
use App\Services\Rag\Contracts\Embedder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * 取り込み: 読み込み → 分割 → 埋め込み → 保存（要件定義書 第4.1章）。
 *
 * 読み込むディレクトリの配置:
 *   {path}/candidates/{候補者コード}/rirekisho.md   … 履歴書（先頭付近の「氏名：」行から氏名を取る）
 *   {path}/candidates/{候補者コード}/shokumu.md     … 職務経歴書
 *   {path}/jobs/*.md                                 … 求人票（候補者に属さない）
 *
 * 再実行しても重複しないための規則:
 *   - 同じ場所（候補者の同じ種類の文書 / 求人票は同じファイル）に同じ内容（content_hash）があれば、スキップする
 *   - 同じ場所の内容が変わっていれば、古い文書（とそのチャンク）を消して入れ直す
 *   - 別の場所に同じ内容の文書があれば、スキップする（content_hash は unique）
 *   - --fresh のときは、全件消してから入れ直す
 *
 * 外部 API（埋め込み）が途中で失敗しても DB が中途半端にならないよう、
 * 先に全チャンクの埋め込みを作り、保存（と --fresh の削除）は最後に1つのトランザクションで行う。
 */
class Ingestor
{
    /** 候補者フォルダ内のファイル名 → 文書種別 */
    private const CANDIDATE_FILES = [
        'rirekisho.md' => Document::TYPE_RESUME,
        'shokumu.md' => Document::TYPE_CAREER_HISTORY,
    ];

    public function __construct(
        private readonly Embedder $embedder,
        private readonly int $chunkSize,
        private readonly int $chunkOverlap,
        private readonly int $chunkMinSize,
    ) {}

    /**
     * @param  string  $path  取り込むディレクトリ
     * @param  bool  $fresh  true なら既存の候補者・文書・チャンクを全件消してから取り込む
     * @param  int|null  $chunkSize  分割の上限文字数（null なら設定値）。実験で比べるときに使う
     * @param  int|null  $chunkOverlap  重なりの文字数（null なら設定値）
     */
    public function ingest(string $path, bool $fresh = false, ?int $chunkSize = null, ?int $chunkOverlap = null): IngestReport
    {
        $startedAt = hrtime(true);
        $path = rtrim($path, '/');
        if (! is_dir($path)) {
            throw new InvalidArgumentException("ディレクトリが見つからない: {$path}");
        }

        $size = $chunkSize ?? $this->chunkSize;
        $overlap = $chunkOverlap ?? $this->chunkOverlap;
        $chunker = new Chunker(size: $size, overlap: $overlap, minSize: $this->chunkMinSize);

        // 1. 読み込み: ファイルを集め、取り込むもの・スキップするものを決める
        [$sources, $warnings] = $this->collectSources($path);
        [$plans, $skipped] = $this->plan($sources, $fresh);

        // 2. 分割: 文書ごとにチャンクへ分ける
        foreach ($plans as $i => $plan) {
            $plans[$i]['chunks'] = $chunker->split($plan['content']);
            if ($plans[$i]['chunks'] === []) {
                $skipped[] = "{$plan['source_path']}: 本文が空";
                unset($plans[$i]);
            }
        }
        $plans = array_values($plans);

        // 3. 埋め込み: 全チャンクの本文をまとめて Embedder に渡す（Embedder がバッチに分けて API を呼ぶ）
        $texts = [];
        foreach ($plans as $plan) {
            foreach ($plan['chunks'] as $chunk) {
                $texts[] = $chunk['content'];
            }
        }
        $vectors = $this->embedder->embed($texts);
        if (count($vectors) !== count($texts)) {
            throw new RuntimeException(sprintf('埋め込みの件数が合わない（チャンク %d 件、ベクトル %d 件）', count($texts), count($vectors)));
        }

        // 4. 保存: 1つのトランザクションで書き込む（途中で失敗したら何も変わらない）
        DB::transaction(function () use ($fresh, $plans, $vectors, $size, $overlap): void {
            if ($fresh) {
                // 文書・チャンクは外部キーの cascade で一緒に消える
                Candidate::query()->delete();
                Document::query()->delete();
            }

            $offset = 0;
            foreach ($plans as $plan) {
                $this->save($plan, array_slice($vectors, $offset, count($plan['chunks'])), $size, $overlap);
                $offset += count($plan['chunks']);
            }
        });

        $chars = array_map('mb_strlen', $texts);

        return new IngestReport(
            ingested: array_column($plans, 'source_path'),
            skipped: $skipped,
            warnings: $warnings,
            chunkCount: count($texts),
            averageChars: $chars === [] ? 0.0 : array_sum($chars) / count($chars),
            elapsedMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            chunkSize: $size,
            chunkOverlap: $overlap,
        );
    }

    /**
     * 取り込み元のファイルを集める。
     *
     * @return array{0: list<array{type: string, candidate_code: string|null, file: string, source_path: string}>, 1: list<string>}
     */
    private function collectSources(string $path): array
    {
        $sources = [];
        $warnings = [];

        $candidateDirs = glob("{$path}/candidates/*", GLOB_ONLYDIR) ?: [];
        sort($candidateDirs);
        foreach ($candidateDirs as $dir) {
            $code = basename($dir);
            foreach (self::CANDIDATE_FILES as $fileName => $type) {
                $file = "{$dir}/{$fileName}";
                if (! is_file($file)) {
                    $warnings[] = "candidates/{$code}/{$fileName} が無い";

                    continue;
                }
                $sources[] = [
                    'type' => $type,
                    'candidate_code' => $code,
                    'file' => $file,
                    'source_path' => "candidates/{$code}/{$fileName}",
                ];
            }
        }

        $jobFiles = glob("{$path}/jobs/*.md") ?: [];
        sort($jobFiles);
        foreach ($jobFiles as $file) {
            $sources[] = [
                'type' => Document::TYPE_JOB,
                'candidate_code' => null,
                'file' => $file,
                'source_path' => 'jobs/'.basename($file),
            ];
        }

        if ($sources === []) {
            $warnings[] = "{$path} に candidates/ と jobs/ の文書が見つからない";
        }

        return [$sources, $warnings];
    }

    /**
     * 既存の文書と比べて、取り込むもの（plans）とスキップするもの（skipped）に分ける。
     *
     * @param  list<array{type: string, candidate_code: string|null, file: string, source_path: string}>  $sources
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function plan(array $sources, bool $fresh): array
    {
        $plans = [];
        $skipped = [];
        $seenHashes = []; // 今回の取り込みの中での重複（同じ内容のファイルが2つある）も防ぐ

        foreach ($sources as $source) {
            $content = self::normalize((string) file_get_contents($source['file']));
            $hash = hash('sha256', $content);

            if (isset($seenHashes[$hash])) {
                $skipped[] = "{$source['source_path']}: {$seenHashes[$hash]} と同じ内容";

                continue;
            }

            // --fresh なら既存データは全部消すので、比べる必要がない
            $existing = $fresh ? null : $this->findExisting($source);
            if ($existing !== null && $existing->content_hash === $hash) {
                $skipped[] = "{$source['source_path']}: 変更なし";

                continue;
            }
            if (! $fresh) {
                $sameContent = Document::query()->where('content_hash', $hash)->first();
                if ($sameContent !== null && $sameContent->id !== $existing?->id) {
                    $skipped[] = "{$source['source_path']}: 同じ内容の文書が既にある（{$sameContent->source_path}）";

                    continue;
                }
            }

            $seenHashes[$hash] = $source['source_path'];
            $plans[] = $source + [
                'content' => $content,
                'content_hash' => $hash,
                'replace_id' => $existing?->id, // 内容が変わった文書は、古いものを消して入れ直す
            ];
        }

        return [$plans, $skipped];
    }

    /** 同じ場所にある既存の文書（候補者の同じ種類の文書 / 同じファイルの求人票） */
    private function findExisting(array $source): ?Document
    {
        if ($source['candidate_code'] === null) {
            return Document::query()
                ->whereNull('candidate_id')
                ->where('type', $source['type'])
                ->where('source_path', $source['source_path'])
                ->first();
        }

        return Document::query()
            ->where('type', $source['type'])
            ->whereHas('candidate', fn ($q) => $q->where('code', $source['candidate_code']))
            ->first();
    }

    /**
     * 1文書を保存する（候補者 → 文書 → チャンク）。
     *
     * @param  array<string, mixed>  $plan
     * @param  list<list<float>>  $vectors  $plan['chunks'] と同じ順番のベクトル
     */
    private function save(array $plan, array $vectors, int $size, int $overlap): void
    {
        if ($plan['replace_id'] !== null) {
            Document::query()->whereKey($plan['replace_id'])->delete();
        }

        $candidateId = null;
        if ($plan['candidate_code'] !== null) {
            $candidate = Candidate::query()->firstOrNew(['code' => $plan['candidate_code']]);
            // 氏名は履歴書の「氏名：」行から取る。職務経歴書だけ先に来ても、同じフォルダの履歴書を読む
            $candidate->name = $this->candidateName($plan) ?? $candidate->name ?? $plan['candidate_code'];
            $candidate->save();
            $candidateId = $candidate->id;
        }

        $document = Document::create([
            'candidate_id' => $candidateId,
            'type' => $plan['type'],
            'title' => self::title($plan['content'], basename($plan['source_path'])),
            'source_path' => $plan['source_path'],
            'content' => $plan['content'],
            'content_hash' => $plan['content_hash'],
        ]);

        foreach ($plan['chunks'] as $index => $chunk) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'token_estimate' => mb_strlen($chunk['content']),
                // section は Chunker が見出しから取ったもの。画面の「職務経歴書・スキル」表示やフィルタに使う
                'metadata' => [
                    'type' => $plan['type'],
                    'section' => $chunk['section'],
                    'chunk_size' => $size,
                    'chunk_overlap' => $overlap,
                ],
                'embedding' => $vectors[$index],
            ]);
        }
    }

    /** 同じ候補者フォルダの履歴書から氏名を取る。見つからなければ null */
    private function candidateName(array $plan): ?string
    {
        $resume = dirname($plan['file']).'/rirekisho.md';
        $text = $plan['type'] === Document::TYPE_RESUME
            ? $plan['content']
            : (is_file($resume) ? (string) file_get_contents($resume) : '');

        return self::extractName($text);
    }

    /** 「氏名：山田 太郎」（全角・半角コロンどちらでも）の行から氏名を取る */
    public static function extractName(string $text): ?string
    {
        if (preg_match('/^\s*氏名\s*[：:]\s*(.+?)\s*$/mu', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** 文書名: 最初の見出し。見出しが無ければファイル名 */
    private static function title(string $content, string $fallback): string
    {
        if (preg_match('/^#{1,6}\s+(.+?)\s*$/mu', $content, $m) === 1) {
            return $m[1];
        }

        return $fallback;
    }

    /** 改行コードを揃え、前後の空白を落とす（同じ内容なら同じ content_hash になるように） */
    private static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
