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
  - 未対応: コードブロック（```）内の `# ...` 行も見出し扱いになる。サンプル文書にはコードブロックを入れない（RS-003 の生成時に検査して弾く）
  - Chunker は `section` を返すだけ。`metadata.section` への格納は Ingestor（RS-003）で行い、そこでテストしている

## 取り込みとダミーデータ（RS-003 で決定）
- A-01 は「要件定義書どおり Claude API で生成」に決定。CLAUDE.md のガードレールもこれに合わせて修正した
- ダミーデータの設計図は `app/Services/Rag/Samples/SampleRoster.php`（候補者20名・求人票10件・評価10問）。生成前に「誰がどの条件に当てはまるか」を決め、eval.json も同じ設計図から作る
  - 評価の質問に関わる語（Laravel / Kubernetes / 機械学習 / Vue / エンジニア採用）は、正解でない候補者の文書には書かせない（`RESERVED_WORDS`。プロンプトで伝え、生成後にも検査）。言い換え候補者（C002・C013）もこの仕組みで製品名を書かない
  - Claude には見出しごとの本文だけを JSON（構造化出力）で返させ、見出し・「氏名：」行はコードで組み立てる
  - 生成後の検査: 連絡先・住所・URL・生年月日らしき文字列、コードブロック、`#` 見出し、禁止語。違反は理由を添えて最大3回まで作り直させる
- 生成物 `storage/app/samples/` と `storage/app/eval.json` はコミットする（`storage/app/.gitignore` で除外を解除）。既存ファイルは `--force` なしでは作り直さない
- ClaudeClient は RS-003 では JSON 生成だけ持つ（回答生成は RS-005）。`anthropic-version` は要件定義書の 2023-06-01 ではなく現行の `2026-10-21` を既定にした（`ANTHROPIC_VERSION` で変更可）。拒否（refusal）に備えて `fallbacks: "default"`（beta `server-side-fallback-2026-07-01`）を付ける
- 外部 API は SDK を入れず、要件定義書どおり Laravel の Http クライアントで直接呼ぶ。429・5xx・接続エラーは 1s / 2s / 4s の指数バックオフで3回まで再試行（`rag.embedding.retry_delays_ms`）
- Ingestor の重複防止: 同じ場所（候補者×種別 / 求人票はファイル）で content_hash が同じならスキップ、違えば古い文書を消して入れ直す。別の場所に同じ内容があればスキップ
- Ingestor は先に全チャンクを埋め込み、保存（と `--fresh` の削除）は最後に1トランザクションで行う。API が失敗しても DB は変わらない
- `chunks.metadata` には type / section / chunk_size / chunk_overlap を入れる。`--chunk-size` / `--overlap` を変えて比べるときは `--fresh` で入れ直す（内容が同じ文書はスキップされるため）
- テストでは phpunit.xml で API キーをダミー値に固定し、TestCase で `Http::preventStrayRequests()` を有効にする（偽装していない送信は例外）

## 検索（RS-004 で決定）
- 範囲は要件定義書 第8章ステップ4どおり（候補者検索・求人票の検索・JobMatcher・`rag:search` / `rag:match`）。feature_list の RS-004 も合わせて広げた（開発者の判断）
- 1段目 `Retriever`: 生SQL（`DB::select`）で `ORDER BY c.embedding <=> ?::vector LIMIT chunk_limit`。類似度は `1 - (embedding <=> q)`。埋め込み前（NULL）のチャンクは除く
  - 対象: 候補者（`candidate_id IS NOT NULL`）/ 求人票（`type = 'job'`）。候補者は `--doc-type` で履歴書・職務経歴書に絞れる（WHERE で絞る）
  - 引数の誤り（空の質問を含む）は埋め込み API を呼ぶ前に例外にする。`--top-k` / `--limit` は整数のみ受け付け、`chunk_limit` の上限は 1000（`SearchOptions::MAX_CHUNK_LIMIT`）
  - `ORDER BY` は距離だけにした（HNSW 索引が使える形を保つため。同じ距離の並びは決めない）。索引が使われるかは RS-007 の `rag:explain` で確かめる
- 2段目 `GroupRanker`（文書単位。求人票の検索）と `CandidateRanker`（キーだけ候補者に替えたサブクラス）。DB・設定を読まない純粋クラス。設定は `SearchOptions` が config とコマンドのオプションから集めて渡す
  - 下限未満を捨てる → まとめる → スコア → 降順で top_k。同点はヒット数の多い順、次にキーの小さい順
  - `avg3` はヒットが3件未満ならある分だけの平均（3で割らない）
  - `max_plus_hits` = 最大類似度 + `hit_bonus`(0.01) × min(ヒット数 − 1, `hit_bonus_max_hits`(5))。要件定義書は「小さな加点」とだけ書いているので数値はリポジトリで決めた（開発者の判断）
- `JobMatcher`: 求人票の見出し `必須条件` / `必須条件`+`歓迎条件` / 全文 を取り出し、1本のベクトルにして候補者検索に渡す。要件定義書の「必須スキル」はサンプル求人票では `必須条件` という見出し
  - 本文はチャンクではなく文書（`documents.content`）から見出しで取る。チャンクは重なり（overlap）を持つので、つなげると同じ文が2回入るため
- `queries` への記録（`mode` / `source_document_id` 列の追加を含む）は RS-005 で行う。RS-004 のコマンドは記録しない

## 方針
- LangChain 等は使わず、外部 API は Laravel の Http クライアントで直接呼ぶ
- ベクトル検索は生SQL（pgvector の演算子）。類似度を画面で見せる
- 外部 API はインターフェース / クラスに閉じ、テストは `Http::fake()` で行う（テストはネットワークを使わない）
- 学習用なので日本語コメントを多めに書く
- 候補者データは架空データのみ

## 未決事項（開発者が決める）
| ID | 内容 | メモ |
| --- | --- | --- |
| A-01 | ダミーデータの生成方法 | **決定（RS-003）**: Claude API で生成してコミットする |
| A-02 | Recall@k の閾値を check.sh に入れるか | RS-007 で決める |
