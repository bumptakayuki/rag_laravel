<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問履歴（要件定義書 第3章）。
 * 外部キーは持たず、検索した chunk_id と類似度を retrieved に JSON で記録する
 * （後で文書を取り込み直しても履歴は残る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queries', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            // top_k・類似度の下限・フィルタなど実行時の設定
            $table->jsonb('params');
            // 検索された chunk_id と類似度の一覧、候補者ごとの集約結果（スコア・ヒット数）
            $table->jsonb('retrieved');
            // 検索のみモード（RS-007）では生成しないので NULL を許す
            $table->text('prompt')->nullable();
            $table->text('answer')->nullable();
            // 埋め込み・検索・生成それぞれの所要時間（ミリ秒）
            $table->jsonb('latency_ms');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queries');
    }
};
