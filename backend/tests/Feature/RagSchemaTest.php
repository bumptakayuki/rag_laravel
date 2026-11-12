<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Rag\Chunker;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 要件定義書 第3章のテーブル・制約が、テスト DB（rag_test）に実際にできているかを確かめる。
 */
class RagSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_code_is_unique(): void
    {
        Candidate::create(['code' => 'C001', 'name' => '架空太郎']);

        $this->expectException(UniqueConstraintViolationException::class);
        Candidate::create(['code' => 'C001', 'name' => '架空次郎']);
    }

    public function test_document_content_hash_is_unique(): void
    {
        $this->makeDocument(null, Document::TYPE_JOB, 'same-hash');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeDocument(null, Document::TYPE_JOB, 'same-hash');
    }

    public function test_candidate_has_at_most_one_document_per_type(): void
    {
        $candidate = Candidate::create(['code' => 'C001', 'name' => '架空太郎']);
        $this->makeDocument($candidate, Document::TYPE_RIREKISHO, 'h1');
        $this->makeDocument($candidate, Document::TYPE_SHOKUMU, 'h2');

        $this->assertSame(2, $candidate->documents()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeDocument($candidate, Document::TYPE_RIREKISHO, 'h3');
    }

    public function test_job_postings_without_candidate_can_be_many(): void
    {
        // candidate_id が NULL 同士は unique 制約にかからない
        $this->makeDocument(null, Document::TYPE_JOB, 'job1');
        $this->makeDocument(null, Document::TYPE_JOB, 'job2');

        $this->assertSame(2, Document::whereNull('candidate_id')->count());
    }

    public function test_document_type_is_restricted(): void
    {
        $this->expectException(QueryException::class);
        $this->makeDocument(null, 'unknown', 'h1');
    }

    public function test_embedding_column_has_configured_dimensions(): void
    {
        $type = DB::selectOne(
            "SELECT format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = 'chunks'::regclass AND attname = 'embedding'"
        )->type;

        $this->assertSame('vector('.config('rag.embedding.dimensions').')', $type);
    }

    public function test_chunk_stores_metadata_and_embedding(): void
    {
        $document = $this->makeDocument(null, Document::TYPE_JOB, 'h1');
        $dimensions = config('rag.embedding.dimensions');
        $embedding = array_fill(0, $dimensions, 0.0);
        $embedding[0] = 0.5;

        $chunk = Chunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'PHP の経験 5年',
            'token_estimate' => 10,
            'metadata' => ['type' => Document::TYPE_JOB, 'section' => '必須スキル'],
            'embedding' => $embedding,
        ]);

        $fresh = $chunk->fresh();
        $this->assertSame('必須スキル', $fresh->metadata['section']);
        $this->assertCount($dimensions, $fresh->embedding);
        $this->assertEqualsWithDelta(0.5, $fresh->embedding[0], 1e-6);

        // jsonb なので SQL で section を取り出してフィルタできる
        $this->assertSame(1, DB::table('chunks')->whereRaw("metadata->>'section' = ?", ['必須スキル'])->count());
    }

    public function test_embedding_can_be_null_before_embedding(): void
    {
        $document = $this->makeDocument(null, Document::TYPE_JOB, 'h1');

        $chunk = $document->chunks()->create(['chunk_index' => 0, 'content' => '本文', 'token_estimate' => 2]);

        $this->assertNull($chunk->fresh()->embedding);
        $this->assertSame([], $chunk->fresh()->metadata);
    }

    public function test_chunk_index_is_unique_within_document(): void
    {
        $document = $this->makeDocument(null, Document::TYPE_JOB, 'h1');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'a', 'token_estimate' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'b', 'token_estimate' => 1]);
    }

    public function test_deleting_document_deletes_its_chunks(): void
    {
        $document = $this->makeDocument(null, Document::TYPE_JOB, 'h1');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'a', 'token_estimate' => 1]);

        $document->delete();

        $this->assertSame(0, Chunk::count());
    }

    public function test_deleting_candidate_deletes_documents_and_chunks(): void
    {
        $candidate = Candidate::create(['code' => 'C001', 'name' => '架空太郎']);
        $document = $this->makeDocument($candidate, Document::TYPE_SHOKUMU, 'h1');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'a', 'token_estimate' => 1]);

        $candidate->delete();

        $this->assertSame(0, Document::count());
        $this->assertSame(0, Chunk::count());
    }

    public function test_query_log_stores_json_columns(): void
    {
        $log = QueryLog::create([
            'question' => 'PHPの経験がある人',
            'params' => ['top_k' => 5, 'min_similarity' => 0.0],
            'retrieved' => ['chunks' => [['chunk_id' => 1, 'similarity' => 0.82]]],
            'latency_ms' => ['embed' => 120, 'search' => 8, 'generate' => 2300],
        ]);

        $fresh = $log->fresh();
        $this->assertSame(5, $fresh->params['top_k']);
        $this->assertSame(0.82, $fresh->retrieved['chunks'][0]['similarity']);
        $this->assertNull($fresh->answer); // 検索のみモードでは回答なし
    }

    public function test_chunker_from_container_uses_config(): void
    {
        config()->set('rag.chunk', ['size' => 10, 'overlap' => 0, 'min_size' => 0]);

        $chunks = app(Chunker::class)->split(str_repeat('あ', 25));

        $this->assertSame([10, 10, 5], array_map(fn ($c) => mb_strlen($c['content']), $chunks));
    }

    private function makeDocument(?Candidate $candidate, string $type, string $hash): Document
    {
        return Document::create([
            'candidate_id' => $candidate?->id,
            'type' => $type,
            'title' => "{$type}.md",
            'source_path' => "samples/{$type}.md",
            'content' => '本文',
            'content_hash' => $hash,
        ]);
    }
}
