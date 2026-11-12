# Architecture

正本は要件定義書（Claude Docs）: https://claude.ai/artifact/8SHGJaz6vGQhBTxX14cfsk
UI イメージ（Design）: https://claude.ai/artifact/S7iqpSegkDtDeJ3NhHgbyg
このファイルは要点と、リポジトリ側で決めたことだけを書く。

## 目的
RAG の仕組みを実装しながら理解するための学習用アプリ。候補者（架空）の履歴書・職務経歴書を自然文で検索し、根拠付きで回答する。

## 処理の流れ
```txt
取り込み: storage/app/samples/ → Chunker（分割）→ Embedder（OpenAI）→ chunks.embedding
質問:     質問 → Embedder → Retriever（生SQL <=> で上位 chunk_limit）
              → CandidateRanker（候補者ごとに集約・top_k）
              → PromptBuilder → ClaudeClient → 回答 [番号]
              → queries に記録
```

## 環境（RS-001 で決定）
| 区分 | 採用 |
| --- | --- |
| 実行環境 | Docker Compose（`compose.yaml`）。Docker ランタイムは OrbStack |
| app | `docker/app/Dockerfile`（php:8.5-cli + pdo_pgsql + composer）、`php artisan serve` |
| db | `pgvector/pgvector:pg17`。開発 DB `rag`、テスト DB `rag_test` |
| ポート | app 18080、db 54320（Docker Desktop が 8000 / 8080 / 5432 を使っているため） |
| Laravel | 13.x（`backend/`） |
| 画面 | Blade + Tailwind CDN。Vite / npm は使わない |
| PHP | ローカル PHP は使わない。check.sh もコンテナ内で実行する |

## データと分割（RS-002 で決定）
- テーブルは要件定義書 第3章のとおり（candidates / documents / chunks / queries）。追加した制約は次の4つ
  - `documents.candidate_id` は cascade delete（候補者を消すと文書・チャンクも消える）
  - `documents.type` に CHECK 制約（rirekisho / shokumu / job のみ）
  - `chunks` に `(document_id, chunk_index)` の unique
  - `chunks.embedding` は NULL 可（分割直後は埋め込み前のため。取り込み完了後は RS-003 で全件埋める）
- `queries.prompt` / `answer` は NULL 可（検索のみモードでは生成しない）
- `queries` の Model 名は `QueryLog`（`Query` だと Eloquent の `query()` と紛らわしいため）
- vector 型は Laravel 13 本体の `Blueprint::vector()` と `AsVector` キャストを使う。`pgvector/pgvector` パッケージは入れない
- Chunker（`app/Services/Rag/Chunker.php`）の規則
  - 見出しごとにセクションを分け、見出し名を `section` にする。見出し行は本文に入れない
  - 本文の上限は `size - overlap` 文字。重なりを足しても1チャンクは必ず `size` 文字以内
  - 長い段落は上限内の最後の「。！？!?」か改行で切る（上限の半分より前にしかなければ文字数で切る）
  - `min_size` 未満の段落は上限に収まる範囲で次と結合する。`min_size`（初期値 150）は要件定義書に数値がないのでリポジトリで決めた
  - 重なりはセクション内だけ（見出しをまたがない）
  - 未対応: コードブロック（```）内の `# ...` 行も見出し扱いになる。サンプル文書にコードブロックが出るなら RS-003 で対処
  - Chunker は `section` を返すだけ。`metadata.section` への格納は Ingestor（RS-003）で行い、そこでテストする

## 方針
- LangChain 等は使わず、外部 API は Laravel の Http クライアントで直接呼ぶ
- ベクトル検索は生SQL（pgvector の演算子）。類似度を画面で見せる
- 外部 API はインターフェース / クラスに閉じ、テストは `Http::fake()` で行う（テストはネットワークを使わない）
- 学習用なので日本語コメントを多めに書く
- 候補者データは架空データのみ

## 未決事項（開発者が決める）
| ID | 内容 | メモ |
| --- | --- | --- |
| A-01 | ダミーデータの生成方法 | 要件定義書は `rag:seed-samples` が Claude API で生成してコミット。CLAUDE.md は「Seeder / Faker ja_JP」。どちらも架空データだが、RS-003 着手前に揃える |
| A-02 | Recall@k の閾値を check.sh に入れるか | RS-007 で決める |
