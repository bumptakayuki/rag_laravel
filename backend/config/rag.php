<?php

/**
 * RAG の設定（要件定義書 第6章）。値はすべて環境変数から読む。
 *
 * 項目はタスクごとに必要になった分だけ足していく（RS-002 時点では埋め込みの次元数と分割設定）。
 */
return [

    'embedding' => [
        // 埋め込みモデル（OpenAI）。RS-003 で使う
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),

        // ベクトルの次元数。chunks.embedding の vector(次元数) はマイグレーション時にこの値で作る。
        // モデルを変えて次元数が変わる場合は、マイグレーションをやり直す必要がある
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
    ],

    'chunk' => [
        // 1チャンクの上限文字数（mb_strlen で数える。重なり分を含む）
        'size' => (int) env('RAG_CHUNK_SIZE', 600),

        // 前のチャンク末尾を何文字重ねるか
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 100),

        // これより短い段落は「小さすぎる」とみなして次の段落と結合する。
        // 要件定義書に数値の指定がないため、リポジトリ側で決めた初期値（docs/architecture.md）
        'min_size' => (int) env('RAG_CHUNK_MIN_SIZE', 150),
    ],

];
