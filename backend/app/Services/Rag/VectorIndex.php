<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\DB;

/**
 * chunks.embedding のベクトル索引（HNSW）を作る・消す（rag:index。要件定義書 第3章・第5章）。
 *
 * 初期状態では索引を作らない。索引なしでは全チャンクとの距離を計算して並べ替える（Seq Scan + Sort。正確だが件数に比例して遅い）。
 * HNSW 索引があると、グラフをたどって近いものだけを見る（Index Scan。速いが近似で、取りこぼすことがある）。
 * どちらが使われたかは rag:explain の実行計画で確かめる。
 *
 * 演算子クラスは vector_cosine_ops（検索で使う <=> = コサイン距離に合わせる。ほかの距離用の索引は <=> では使われない）。
 * migration にしないのは、作る・消すを何度も繰り返す実験用だから（docs/architecture.md）。
 */
class VectorIndex
{
    /** 索引の名前 */
    public const NAME = 'chunks_embedding_hnsw_idx';

    /**
     * 索引を作る。すでにあれば何もしない。
     *
     * m（各点からつなぐ数）と ef_construction（作るときに見る候補の数）は大きいほど精度が上がり、作るのが遅くなる。
     * pgvector の既定は m = 16、ef_construction = 64
     *
     * @return bool 新しく作ったら true
     */
    public function create(int $m = 16, int $efConstruction = 64): bool
    {
        if ($this->exists()) {
            return false;
        }
        // 数値は int に型を付けてから埋め込む（WITH 句のパラメータはバインドできない）
        DB::statement(sprintf(
            'CREATE INDEX %s ON chunks USING hnsw (embedding vector_cosine_ops) WITH (m = %d, ef_construction = %d)',
            self::NAME,
            $m,
            $efConstruction,
        ));
        // 索引を作ったら統計を取り直す（planner が件数を正しく見積もれるように）
        DB::statement('ANALYZE chunks');

        return true;
    }

    /**
     * 索引を消す。なければ何もしない。
     *
     * @return bool 消したら true
     */
    public function drop(): bool
    {
        if (! $this->exists()) {
            return false;
        }
        DB::statement('DROP INDEX '.self::NAME);

        return true;
    }

    public function exists(): bool
    {
        return $this->definition() !== null;
    }

    /** 索引の定義（CREATE INDEX 文）。なければ null */
    public function definition(): ?string
    {
        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            ['chunks', self::NAME],
        );

        return $row?->indexdef;
    }

    /** 索引の大きさ（例: "3200 kB"）。なければ null */
    public function size(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return DB::selectOne('SELECT pg_size_pretty(pg_relation_size(?::regclass)) AS size', [self::NAME])->size;
    }
}
