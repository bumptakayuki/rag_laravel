<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「RAGなし比較」の回答を記録する列を足す（要件定義書 第5章。RS-007）。
 *
 * 同じ質問を資料なしで Claude に送った回答。質問画面のチェックボックスを付けたときだけ入る。
 * 画面は記録から結果を組み立て直す（POST → リダイレクト → GET）ので、表示するには記録しておく必要がある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->text('answer_without_rag')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('answer_without_rag');
        });
    }
};
