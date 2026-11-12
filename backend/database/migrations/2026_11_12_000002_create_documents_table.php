<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 取り込んだ文書（要件定義書 第3章）。
 * 候補者の履歴書・職務経歴書と、候補者に属さない求人票を同じテーブルに入れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // 求人票は候補者に属さないので NULL
            $table->foreignId('candidate_id')->nullable()->constrained()->cascadeOnDelete();
            // rirekisho（履歴書）/ shokumu（職務経歴書）/ job（求人票）
            $table->string('type');
            $table->string('title');
            $table->string('source_path');
            // 全文（候補者詳細画面で表示する）
            $table->text('content');
            // 同じ内容を二重に取り込まないためのハッシュ
            $table->string('content_hash')->unique();
            $table->timestamps();

            // 候補者1人あたり履歴書・職務経歴書は各1件まで。
            // PostgreSQL の unique は NULL 同士を別物として扱うので、candidate_id が NULL の求人票は何件でも入る
            $table->unique(['candidate_id', 'type']);
        });

        // 種別の書き間違いを DB でも防ぐ
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('rirekisho', 'shokumu', 'job'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
