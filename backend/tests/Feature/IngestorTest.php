<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\Ingestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * 取り込み（Ingestor・rag:ingest・rag:chunks）のテスト。
 * 埋め込み API は Http::fake() で偽装する（ネットワークは使わない）。
 * 取り込み元の文書はテストごとに一時ディレクトリへ作る（storage/app/samples は使わない）。
 */
class IngestorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->dir = sys_get_temp_dir().'/rag-ingest-test-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->fakeEmbeddings();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** 送られた input の件数ぶん、正しい次元数のベクトルを返す */
    private function fakeEmbeddings(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions');
        Http::fake(['api.openai.com/*' => function (Request $request) use ($dimensions) {
            $data = [];
            foreach ($request['input'] as $i => $text) {
                $data[] = ['index' => $i, 'embedding' => array_fill(0, $dimensions, 0.001 * (($i % 100) + 1))];
            }

            return Http::response(['data' => $data]);
        }]);
    }

    /** 候補者 $count 名（履歴書・職務経歴書）と求人票 $jobs 件の Markdown を作る */
    private function writeSamples(int $count = 20, int $jobs = 10): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $code = sprintf('C%03d', $n);
            $this->writeFile("candidates/{$code}/rirekisho.md", $this->resume($code, "架空 太郎{$n}"));
            $this->writeFile("candidates/{$code}/shokumu.md", $this->career($code, "架空 太郎{$n}"));
        }
        for ($n = 1; $n <= $jobs; $n++) {
            $this->writeFile(sprintf('jobs/J%02d.md', $n), "# 求人票：職種{$n}\n\n会社名：株式会社サンプル{$n}\n\n## 業務内容\n\n".str_repeat("求人{$n}の業務内容です。", 20));
        }
    }

    private function resume(string $code, string $name): string
    {
        return "# 履歴書\n\n氏名：{$name}\n希望職種：バックエンドエンジニア\n\n## 学歴\n\n{$code} はサンプル大学 情報学部を卒業。\n\n## 資格\n\n基本情報技術者\n\n## 志望動機\n\n".str_repeat("{$code} の志望動機です。", 30);
    }

    private function career(string $code, string $name): string
    {
        return "# 職務経歴書\n\n氏名：{$name}\n\n## 職務要約\n\n{$code} は PHP で開発してきました。\n\n## スキル\n\n- PHP\n- PostgreSQL\n\n## 自己PR\n\n".str_repeat("{$code} の自己PRです。", 40);
    }

    private function writeFile(string $relative, string $content): void
    {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$relative}"));
        File::put("{$this->dir}/{$relative}", $content);
    }

    private function ingest(bool $fresh = false)
    {
        return app(Ingestor::class)->ingest($this->dir, fresh: $fresh);
    }

    public function test_ingests_20_candidates_40_documents_and_jobs_with_embeddings(): void
    {
        $this->writeSamples();

        $report = $this->ingest();

        $this->assertCount(50, $report->ingested);
        $this->assertSame(20, Candidate::count());
        $this->assertSame(20, Document::where('type', Document::TYPE_RESUME)->count());
        $this->assertSame(20, Document::where('type', Document::TYPE_CAREER_HISTORY)->count());
        $this->assertSame(10, Document::where('type', Document::TYPE_JOB)->whereNull('candidate_id')->count());
        $this->assertSame($report->chunkCount, Chunk::count());
        $this->assertGreaterThan(50, Chunk::count());
        // 全チャンクの embedding が NULL でない
        $this->assertSame(0, Chunk::whereNull('embedding')->count());
    }

    public function test_committed_samples_ingest_as_20_candidates_40_documents_and_10_jobs(): void
    {
        // rag:seed-samples で生成してコミットしたダミーデータそのもの（埋め込みだけ偽装する）
        app(Ingestor::class)->ingest(storage_path('app/samples'));
        app(Ingestor::class)->ingest(storage_path('app/samples')); // 再実行しても増えない

        $this->assertSame(20, Candidate::count());
        $this->assertSame(40, Document::whereNotNull('candidate_id')->count());
        $this->assertSame(10, Document::where('type', Document::TYPE_JOB)->count());
        $this->assertSame(0, Chunk::whereNull('embedding')->count());
        foreach (Candidate::withCount('documents')->get() as $candidate) {
            $this->assertSame(2, $candidate->documents_count, $candidate->code);
        }
        $this->assertSame(0, Chunk::whereNull('metadata->section')->count());
    }

    public function test_each_candidate_has_one_resume_and_one_career_history(): void
    {
        $this->writeSamples();
        $this->ingest();

        foreach (Candidate::with('documents')->get() as $candidate) {
            $types = $candidate->documents->pluck('type')->sort()->values()->all();
            $this->assertSame([Document::TYPE_RESUME, Document::TYPE_CAREER_HISTORY], $types, $candidate->code);
        }
        // 氏名は履歴書の「氏名：」行から取る
        $this->assertSame('架空 太郎7', Candidate::where('code', 'C007')->value('name'));
    }

    public function test_rerun_does_not_duplicate_or_call_api(): void
    {
        $this->writeSamples();
        $this->ingest();
        $counts = [Candidate::count(), Document::count(), Chunk::count()];
        $sent = Http::recorded()->count();

        $report = $this->ingest();

        $this->assertSame($counts, [Candidate::count(), Document::count(), Chunk::count()]);
        $this->assertSame([], $report->ingested);
        $this->assertCount(50, $report->skipped);
        // 変更が無ければ埋め込み API も呼ばない
        $this->assertSame($sent, Http::recorded()->count());
    }

    public function test_changed_document_is_replaced_not_duplicated(): void
    {
        $this->writeSamples(count: 2, jobs: 1);
        $this->ingest();
        $oldId = Document::where('source_path', 'candidates/C001/shokumu.md')->value('id');

        $this->writeFile('candidates/C001/shokumu.md', $this->career('C001', '架空 太郎1')."\n\n追記しました。");
        $this->writeFile('jobs/J01.md', "# 求人票：職種1（改訂）\n\n## 業務内容\n\n内容を変えた。");
        $report = $this->ingest();

        $this->assertSame(['candidates/C001/shokumu.md', 'jobs/J01.md'], $report->ingested);
        $this->assertSame(1, Document::whereHas('candidate', fn ($q) => $q->where('code', 'C001'))->where('type', Document::TYPE_CAREER_HISTORY)->count());
        $this->assertSame(1, Document::where('type', Document::TYPE_JOB)->count());
        $this->assertNotSame($oldId, Document::where('source_path', 'candidates/C001/shokumu.md')->value('id'));
        // 古い文書のチャンクは cascade で消えている
        $this->assertSame(0, Chunk::where('document_id', $oldId)->count());
        $this->assertSame(5, Document::count());
    }

    public function test_fresh_deletes_everything_before_ingesting(): void
    {
        $this->writeSamples(count: 3, jobs: 1);
        $this->ingest();
        File::deleteDirectory("{$this->dir}/candidates/C003");

        $report = $this->ingest(fresh: true);

        $this->assertCount(5, $report->ingested);
        $this->assertSame(['C001', 'C002'], Candidate::orderBy('code')->pluck('code')->all());
        $this->assertSame(5, Document::count());
    }

    public function test_section_and_settings_are_stored_in_metadata(): void
    {
        $this->writeSamples(count: 1, jobs: 0);
        $this->ingest();

        $chunks = Document::where('type', Document::TYPE_CAREER_HISTORY)->firstOrFail()->chunks;
        $sections = $chunks->pluck('metadata.section')->unique()->values()->all();

        $this->assertSame(['職務経歴書', '職務要約', 'スキル', '自己PR'], $sections);
        $this->assertSame(Document::TYPE_CAREER_HISTORY, $chunks->first()->metadata['type']);
        $this->assertSame((int) config('rag.chunk.size'), $chunks->first()->metadata['chunk_size']);
        $this->assertSame((int) config('rag.chunk.overlap'), $chunks->first()->metadata['chunk_overlap']);
        // 見出し行は本文に入れない。chunk_index は 0 から連番
        $this->assertFalse($chunks->contains(fn ($c) => str_contains($c->content, '## ')));
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());
        $this->assertSame(mb_strlen($chunks->first()->content), $chunks->first()->token_estimate);
    }

    public function test_chunk_options_override_settings(): void
    {
        $this->writeSamples(count: 1, jobs: 0);

        app(Ingestor::class)->ingest($this->dir, chunkSize: 200, chunkOverlap: 20);

        $this->assertTrue(Chunk::all()->every(fn ($c) => $c->metadata['chunk_size'] === 200
            && $c->metadata['chunk_overlap'] === 20
            && mb_strlen($c->content) <= 200));
    }

    public function test_embedding_failure_leaves_database_unchanged(): void
    {
        $this->writeSamples(count: 2, jobs: 1);
        $this->ingest();
        $counts = [Candidate::count(), Document::count(), Chunk::count()];

        // 2回目は API が失敗し続ける。--fresh でも、埋め込みが揃う前は何も消さない
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        try {
            $this->ingest(fresh: true);
            $this->fail('例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->assertSame($counts, [Candidate::count(), Document::count(), Chunk::count()]);
    }

    public function test_same_content_in_two_files_is_ingested_once(): void
    {
        $this->writeSamples(count: 0, jobs: 1);
        File::copy("{$this->dir}/jobs/J01.md", "{$this->dir}/jobs/J02.md");

        $report = $this->ingest();

        $this->assertSame(['jobs/J01.md'], $report->ingested);
        $this->assertSame(['jobs/J02.md: jobs/J01.md と同じ内容'], $report->skipped);
        $this->assertSame(1, Document::count());
    }

    public function test_missing_file_is_reported_as_warning(): void
    {
        $this->writeFile('candidates/C001/rirekisho.md', $this->resume('C001', '架空 花子'));

        $report = $this->ingest();

        $this->assertSame(['candidates/C001/shokumu.md が無い'], $report->warnings);
        $this->assertSame('架空 花子', Candidate::where('code', 'C001')->value('name'));
    }

    public function test_extract_name_accepts_full_and_half_width_colon(): void
    {
        $this->assertSame('山田 花子', Ingestor::extractName("# 履歴書\n\n氏名：山田 花子\n"));
        $this->assertSame('山田 花子', Ingestor::extractName('氏名: 山田 花子'));
        $this->assertNull(Ingestor::extractName('名前なし'));
    }

    public function test_ingest_command_shows_summary(): void
    {
        $this->writeSamples(count: 2, jobs: 1);

        $this->artisan('rag:ingest', ['path' => $this->dir])
            ->expectsOutputToContain('今回の取り込み')
            ->expectsOutputToContain('平均文字数')
            ->expectsOutputToContain('所要時間')
            ->expectsOutputToContain('embedding が NULL のチャンク')
            ->assertSuccessful();

        $this->assertSame(5, Document::count());
        $this->assertSame(0, Chunk::whereNull('embedding')->count());
    }

    public function test_ingest_command_fails_for_missing_directory(): void
    {
        $this->artisan('rag:ingest', ['path' => $this->dir.'/nope'])
            ->expectsOutputToContain('ディレクトリが見つからない')
            ->assertFailed();
    }

    public function test_chunks_command_lists_chunks_of_document(): void
    {
        $this->writeSamples(count: 1, jobs: 0);
        $this->ingest();
        $document = Document::where('type', Document::TYPE_CAREER_HISTORY)->firstOrFail();

        $this->artisan('rag:chunks', ['document_id' => $document->id])
            ->expectsOutputToContain('職務経歴書 / C001 架空 太郎1 / candidates/C001/shokumu.md')
            ->expectsOutputToContain('自己PR')
            ->assertSuccessful();

        $this->artisan('rag:chunks', ['document_id' => 999999])->assertFailed();
    }
}
