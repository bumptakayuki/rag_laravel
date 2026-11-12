<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 検索対象の断片（要件定義書 第3章）。ベクトル検索はこのテーブルに対して行う。
 *
 * ベクトル索引（HNSW）は初期状態では作らない。実験用コマンド rag:index で追加・削除する（RS-007）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // 文書内の順番（0 始まり）
            $table->integer('chunk_index');
            $table->text('content');
            // おおよその長さ（文字数）
            $table->integer('token_estimate');
            // 文書種別・見出し名（section）など。フィルタ検索や画面表示に使う
            $table->jsonb('metadata')->default('{}');
            // 埋め込みベクトル。次元数は config/rag.php と一致させる。
            // 分割直後は埋め込み前なので NULL を許す（取り込み完了後はすべて埋まる: RS-003）
            $table->vector('embedding', config('rag.embedding.dimensions'))->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
