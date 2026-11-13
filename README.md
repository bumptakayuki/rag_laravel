# rag_laravel — 候補者検索 RAG（学習用）

架空の候補者の履歴書・職務経歴書を自然文で検索し、検索した資料だけを根拠に Claude が回答する、小さな RAG（検索拡張生成）アプリです。
RAG の各工程（分割 → 埋め込み → ベクトル検索 → 集約 → プロンプト → 回答）をコードで追えること、パラメータを変えて結果を比べられることを優先しています。

- フレームワーク: Laravel 13（PHP 8.5）。LangChain などの RAG フレームワークは使わない
- DB: PostgreSQL 17 + pgvector。ベクトル検索は生 SQL（`<=>` 演算子）
- 外部 API: OpenAI Embeddings（埋め込み）、Anthropic Messages（回答生成）。Laravel の Http クライアントで直接呼ぶ
- 画面: Blade + Tailwind CDN
- データ: すべて架空（`storage/app/samples/`。候補者20名 × 2文書 + 求人票10件）

設計の要点は [docs/architecture.md](docs/architecture.md) にあります。

## セットアップ

必要なもの: Docker（Compose v2）。PHP や Composer をローカルに入れる必要はありません（すべてコンテナで動かします）。

```bash
# 1. 設定ファイルを作り、API キーを書く（OPENAI_API_KEY は必須。ANTHROPIC_API_KEY は回答生成に使う）
cp backend/.env.example backend/.env

# 2. 依存パッケージを入れる（vendor/ は backend/ にできる）
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate

# 3. 起動（app: http://localhost:18080 / db: localhost:54320）
docker compose up -d

# 4. テーブルを作る（pgvector 拡張の有効化を含む）
docker compose exec app php artisan migrate

# 5. サンプルを取り込む（分割 → OpenAI で埋め込み → 保存。50文書・約370チャンク）
docker compose exec app php artisan rag:ingest

# 6. ブラウザで開く
open http://localhost:18080
```

- テスト用の DB `rag_test` は、db コンテナの初回起動時に自動で作られます（`docker/db/init/`）
- `bash scripts/init.sh` で環境（コンテナ・PHP・.env の有無など）を確認できます
- API キーは `backend/.env` にだけ書き、リポジトリには入れません

以下のコマンドは、すべて `docker compose exec app php artisan ...` の形で実行します（下では `php artisan` と略します）。

## 使い方

### 画面

| URL | 内容 |
| --- | --- |
| `/` | 質問画面。検索の流れ（件数・時間・実行 SQL）、候補者ランキング、回答、送信したプロンプト全文 |
| `/candidates/{id}` | 候補者詳細。履歴書・職務経歴書の全文と、検索でヒットしたチャンクのハイライト |
| `/jobs`・`/jobs/{id}` | 求人票の一覧・詳細。詳細から「この求人に合う候補者を探す」 |
| `/history` | 実行の履歴。同じ設定で再実行できる |

### コマンド

| コマンド | 内容 | 外部 API |
| --- | --- | --- |
| `rag:ingest [path] [--fresh] [--chunk-size=] [--overlap=]` | 文書を分割・埋め込みして保存する | OpenAI |
| `rag:chunks {文書ID} [--full]` | 1文書がどう分割されたかを確認する | なし |
| `rag:search "質問" [--type=job]` | 検索だけ（候補者 / 求人票） | OpenAI |
| `rag:ask "質問" [--show-prompt]` | 検索 → 回答生成 → 履歴に記録 | OpenAI・Claude |
| `rag:match {求人票の文書ID} [--source=required\|required_preferred\|full]` | 求人票に合う候補者を探す | OpenAI |
| `rag:seed-samples [--force]` | 架空のサンプルと評価データを作り直す（通常は不要。コミット済み） | Claude |

`rag:search` / `rag:ask` / `rag:match` は `--score=` `--top-k=` `--limit=` `--min-similarity=` `--doc-type=` で検索の条件を変えられます。

## 実験する

パラメータを変えて結果を比べられることが、このアプリの中心です（要件定義書 第5章）。

| 実験 | やり方 |
| --- | --- |
| 検索だけ試す | 質問画面の「検索だけ実行」。Claude を呼ばないので、API 費用を抑えて検索の精度を試せる |
| RAG あり / なしを比べる | 質問画面の「RAGなしの回答と比べる」。資料なしで Claude に聞いた回答を横に並べる |
| スコアの決め方を比べる | 質問画面の「スコアの決め方」、または `--score=max\|avg3\|max_plus_hits` |
| チャンクの大きさを比べる | `rag:ingest --fresh --chunk-size=300 --overlap=50` で取り込み直す（設定値は `chunks.metadata` に残る） |
| 求人マッチングの検索文を比べる | 求人票詳細で「必須条件のみ / 必須＋歓迎 / 全文」を切り替える |
| 検索の精度を数値で見る | `rag:eval`（下記） |
| ベクトル索引の効果を見る | `rag:seed-random` → `rag:explain` → `rag:index create` → `rag:explain`（下記） |

### 検索の精度を測る: `rag:eval`

`storage/app/eval.json`（10問。質問と正解の候補者コード）の質問で検索し、次の値を表示します。Claude は呼びません（OpenAI を1回呼びます）。

- Recall@k: 正解の候補者のうち、上位 k 名に入った割合（質問ごとに出し、全体は平均）
- 平均順位: 正解の候補者の順位の平均。1段目の検索（`chunk_limit` 件のチャンク）に1件もヒットしなかった人は「圏外」として別に数える

```bash
php artisan rag:eval                      # 設定の初期値で
php artisan rag:eval --score=avg3         # スコアの決め方を変えて比べる
php artisan rag:eval --doc-type=shokumu   # 職務経歴書だけで検索する
php artisan rag:eval --k=3 --limit=100
```

### ベクトル索引（HNSW）の効果を見る: `rag:index` / `rag:explain` / `rag:seed-random`

索引は初期状態では作りません。索引がないと全チャンクとの距離を計算して並べ替え（正確だが件数に比例して遅い）、HNSW 索引があるとグラフをたどって近いものだけを見ます（速いが近似）。

```bash
# 1. 件数が少ないと全件読みのほうが安い。API を使わずにランダムなベクトルを3万件足す（約15秒）
php artisan rag:seed-random 30000

# 2. 索引なしの実行計画（Seq Scan on chunks → Sort。全チャンクとの距離を計算する）
php artisan rag:explain --random --set=hnsw.ef_search=60   # --random は質問の代わりにランダムなベクトル（API を呼ばない）

# 3. 索引を作って、同じコマンドをもう一度（Index Scan using chunks_embedding_hnsw_idx）
php artisan rag:index create                                # 3万件で数分かかる
php artisan rag:explain --random --set=hnsw.ef_search=60

# 4. 既定の ef_search（40）のままだと、索引があっても planner は Seq Scan を選ぶ（下の「実測」）
php artisan rag:explain --random

# 5. 後片付け
php artisan rag:index drop
php artisan rag:seed-random --purge
```

実測（開発環境。実データ 371 + ランダム 30,000 チャンク、索引は m=16・ef_construction=64、LIMIT 50）:

| 条件 | 実行計画 | 返った行数 | 実行時間 |
| --- | --- | --- | --- |
| 索引なし | Seq Scan → Sort | 50 | 約 480 ms |
| 索引あり・`hnsw.ef_search=40`（既定） | Seq Scan → Sort（planner が索引を選ばない） | 50 | 約 440 ms |
| 索引あり・`hnsw.ef_search=60` | Index Scan using chunks_embedding_hnsw_idx | 50 | 約 18 ms |

- 索引から返る件数の上限は `hnsw.ef_search` です。pgvector は LIMIT が ef_search より大きいと索引では件数を満たせないとみなすため、既定の 40 では LIMIT 50（`RAG_CHUNK_LIMIT`）の検索に索引を使いませんでした（LIMIT を小さくしても、この環境では Seq Scan のままでした）
- 索引の大きさは 237 MB、作成に約 340 秒かかりました。件数・m・ef_construction で変わります
- 索引が使われても、返る行数が LIMIT より少なくなることがあります（ef_search 件までしか索引から取らず、そこから WHERE で絞るため）。テスト（`ExperimentCommandsTest`）では、5,000 件・小さい m の索引で、既定の ef_search のまま索引が選ばれ 40 件だけ返ることを確かめています
- `rag:explain "質問"` は質問を埋め込んで、実際の検索と同じ SQL を `EXPLAIN ANALYZE` します
- `--set=名前=値` で、その1回だけ planner の設定を変えられます（例: `--set=enable_seqscan=off`）
- ランダムなチャンクは実験用の候補者 `RANDOM` にまとめて入り、`chunks.metadata.random = true` が付きます。実際の文章とはほぼ直交する（類似度 0 付近）ので、検索の上位には出てきません

## コードを読む順番

処理の流れに沿って読むと、RAG の各工程がつながります（パスは `backend/` から）。

1. **設定** — `config/rag.php`（分割・検索・回答のパラメータと、その意味）
2. **取り込み**
   1. `app/Services/Rag/Chunker.php` — Markdown を見出し・段落で分割し、重なり（overlap）を付ける
   2. `app/Services/Rag/OpenAiEmbedder.php` — 文をベクトルにする（バッチ・再試行）
   3. `app/Services/Rag/Ingestor.php` — 読み込み → 分割 → 埋め込み → 保存（`rag:ingest`）
3. **検索**
   1. `app/Services/Rag/Retriever.php` — 1段目: 生 SQL の `ORDER BY embedding <=> 質問ベクトル` で近いチャンクを取る
   2. `app/Services/Rag/GroupRanker.php`・`CandidateRanker.php` — 2段目: チャンクを候補者ごとにまとめてスコアを付ける（max / avg3 / max_plus_hits）
   3. `app/Services/Rag/JobMatcher.php` — 求人票の一部を検索文にする
4. **回答生成**
   1. `app/Services/Rag/PromptBuilder.php` — 資料に番号を振ってプロンプトを組み立てる
   2. `app/Services/Rag/ClaudeClient.php` — Messages API を呼ぶ
   3. `app/Services/Rag/RagPipeline.php` — 検索 → 生成 → 記録をつなぎ、工程ごとの時間を測る
5. **画面** — `app/Http/Controllers/SearchController.php` → `resources/views/search.blade.php`。記録から結果を組み立て直すのは `app/Services/Rag/QueryLogRestorer.php`
6. **実験** — `app/Services/Rag/Evaluator.php`（`rag:eval`）、`VectorIndex.php`（`rag:index`）、`RandomChunkSeeder.php`（`rag:seed-random`）、`Retriever::explain()`（`rag:explain`）
7. **全体を1本で** — `tests/Feature/EndToEndTest.php`（取り込み → 検索 → 回答 → 記録 → 画面を、API を偽装して通す）

テーブルは `database/migrations/` にあります（`candidates` / `documents` / `chunks` / `queries`）。

## テストと品質ゲート

```bash
docker compose exec app php artisan test   # テスト（テスト DB rag_test を使う。外部 API は Http::fake() で偽装）
bash scripts/check.sh                      # 品質ゲート（テスト・pint・gitleaks など。最後に GATE: PASS）
```

- テストはネットワークを使いません（偽装していない外部への送信は例外になります）
- `rag:eval` の Recall@k は実 API が必要なので、品質ゲートには入れていません（手で実行して確かめる）

## 開発の進め方

Claude Code と1人で開発しています。ルールは [CLAUDE.md](CLAUDE.md)、タスクと受け入れ条件は [.harness/feature_list.json](.harness/feature_list.json)、引き継ぎは [.harness/progress.md](.harness/progress.md) にあります。
