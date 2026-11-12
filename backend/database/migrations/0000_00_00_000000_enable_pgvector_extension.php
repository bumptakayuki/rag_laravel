<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pgvector 拡張を有効化する（最初に実行されるマイグレーション）。
 *
 * vector 型・<=> 演算子（コサイン距離）・HNSW 索引は、この拡張を有効にした DB でだけ使える。
 * 拡張は DB ごとに有効化が必要なので、開発用の rag とテスト用の rag_test の両方でこれが走る。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
