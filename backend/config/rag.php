<?php

/**
 * RAG の設定（要件定義書 第6章）。値はすべて環境変数から読む。
 *
 * 項目はタスクごとに必要になった分だけ足していく（RS-003 時点では外部 API・埋め込み・分割の設定）。
 * API キーは .env にだけ書く（リポジトリには入れない）。
 */
return [

    // 埋め込み API（OpenAI Embeddings）。OpenAiEmbedder が使う
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],

    // 回答生成・ダミーデータ生成（Anthropic Messages API）。ClaudeClient が使う
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        // API バージョン（anthropic-version ヘッダー）。要件定義書は 2023-06-01 だが、現行の版を既定にした（docs/architecture.md）
        'version' => env('ANTHROPIC_VERSION', '2026-10-21'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 300),
    ],

    'embedding' => [
        // 埋め込みモデル（OpenAI）。RS-003 で使う
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),

        // ベクトルの次元数。chunks.embedding の vector(次元数) はマイグレーション時にこの値で作る。
        // モデルを変えて次元数が変わる場合は、マイグレーションをやり直す必要がある
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),

        // 1回の API 呼び出しで送るテキスト数（要件定義書 第4.1章: 初期値 50）
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 50),

        // 失敗時の再試行の待ち時間（ミリ秒）。要素数 = 再試行回数。指数バックオフで 3 回まで
        'retry_delays_ms' => [1000, 2000, 4000],
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
