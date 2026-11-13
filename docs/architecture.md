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
- `queries` への記録（`mode` / `source_document_id` 列の追加を含む）は RS-005 で行う（→「回答生成と記録」）

## 簡易検索画面（RS-004b で決定）
- 開発者の指示で、RS-006 の質問画面（/）のうち検索部分だけを先に作った。回答・プロンプト表示・queries への記録は RS-005、求人票画面・候補者詳細・履歴は RS-006
- `SearchController@index`（GET /）。フォームは GET（URL で検索条件を共有できる）。`q` がないときはフォームだけ出し、API を呼ばない
- 入力エラーはリダイレクトせずその場で表示する（GET のため）。lang ファイルは入れず、この画面で使う規則のメッセージだけ日本語で書いた
- 「検索の流れ」を見せるため、コントローラーで Embedder → `Retriever::retrieveByVector` → Ranker を工程ごとに呼んで時間を測る。表示用の SQL は `Retriever::sqlForDisplay()`（実行に使う SQL と同じ組み立て）
- 見た目は UI イメージ（Design）の検索画面に合わせた。カードの展開は `<details>` で、JS は使わない
- Tailwind は Play CDN（`cdn.tailwindcss.com/3.4.17`、SRI なし）で読む。ローカルの学習用画面のためで、本番向けではない
- クエリの値が配列（`?q[]=x`）なら空文字にしてから検証する（エスケープで TypeError にしないため）
- 求人票カードから求人票詳細へのリンクは、詳細画面ができる RS-006 で付ける

## 回答生成と記録（RS-005 で決定）
- `RagPipeline` が「検索 → 生成 → 記録」をつなぎ、工程ごとの時間（embed / search / rank / prompt / generate / total）を測る。コマンドは表示だけ
  - `ask`（`rag:ask`）: 候補者検索 → `PromptBuilder` → `ClaudeClient::generateText` → `queries`（mode = question）
  - `match`（`rag:match`）: `JobMatcher::sourceText` → 候補者検索 → `queries`（mode = job_match、`source_document_id` = 求人票）。回答（適合理由）は生成しない
  - 記録の範囲は rag:ask と rag:match（開発者の判断）。`rag:search` と画面は記録しない。mode = job_search は列の値だけ用意し、記録は RS-006 の画面で行う
- `PromptBuilder` は DB・設定を読まない純粋なクラス。上位 top_k 名 × 最大 `chunks_per_candidate`（`RAG_CHUNKS_PER_CANDIDATE`、初期値3）件を、候補者ごとに `## 候補者 C001 氏名` の下へ `[1] (職務経歴書・職務経歴) 本文` の形で並べる。番号は候補者をまたいで通しで振る
  - system は「資料だけを根拠に／根拠の番号を [1] で示す／候補者コードと氏名を書く／関係ない資料は使わない／わからなければ『資料からはわかりません』とだけ答える」
  - 回答中の `[番号]` を拾い、プロンプトにない番号は `unknownCitations` として警告する（Claude の誤りに気づけるように）
- 資料が1件もない（下限で全部除外された）ときは Claude を呼ばずに「資料からはわかりません」を返して記録する（答えが決まっているため。API 費用も抑える）
- `ClaudeClient::generateText` は構造化出力を使わず文章で返させる。`thinking` は送らない（現行モデルでは省略 = adaptive）。`temperature` なども送らない（API バージョン 2026-10-21 では 400）。refusal / max_tokens / 空の応答は例外
- 再試行の待ち時間は `rag.anthropic.retry_delays_ms` に分けた（RS-003 では埋め込み側の設定を流用していた）
- 外部 API の失敗は、`RagPipeline` が工程名（embed / generate）とエラー文をログに出し、`ExternalApiException`（`stage` つき）に包んで投げる。コマンドはこの型だけを捕まえ、ステータスコードとメッセージを表示して FAILURE。失敗した実行は記録しない（queries は結果が出た実行の履歴）。API キーや送った本文はログに出さない
  - DB のエラー（`QueryException` も RuntimeException の仲間）は捕まえずにそのまま投げる（「外部 API の失敗」と取り違えないように。reviewer の指摘）
- 記録（queries への保存）に失敗しても例外にしない。回答は API 費用をかけて作ったので捨てずに返し、`RagResult::$recordError` に理由を入れる。コマンドは回答を表示してから「記録できなかった」と出して FAILURE（reviewer の指摘）
  - 保存は `DB::transaction` で包む（呼び出し元がトランザクション中でもセーブポイントまで戻すだけで済む）
  - 理由（画面・ログ）は PDO のメッセージの1行目（SQLSTATE とエラーの種類）だけにする。QueryException のメッセージには SQL がバインド値ごと入り、PostgreSQL の制約違反では2行目以降の `DETAIL: Failing row contains (...)` に行の値が入る。どちらもプロンプト・回答の全文を含むため（reviewer の指摘）
- 引数の誤り（空の質問・`--chunks-per-candidate` 0 など）は外部 API を呼ぶ前に止める。検索条件の確認には `Retriever::validate()` を使う
- `queries` の追加列（migration `2026_11_13_000001`）
  - `mode`: 既定 `question`（RS-005 より前の行もこれになる）と CHECK 制約（question / job_search / job_match）
  - `source_document_id`: documents への FK。求人票を取り込み直して消えても履歴を残すため `nullOnDelete`（cascade にしない）
- `retrieved` の中身: `counts`（retrieved / kept / total_groups）、`chunks`（1段目で取れた全チャンクの chunk_id・document_id・candidate_id・類似度）、`candidates`（上位 top_k 名のスコア・ヒット数・種別ごとのヒット数）、ask のときは `sources`（資料番号 → chunk_id・candidate_id）
- `prompt` には `[system]` と `[user]` をまとめた全文を入れる
- 注意: jsonb はキーの順番を保たない（短いキー順に並べ替える）。`latency_ms` を工程順に表示するときは、表示側で順番を決める（RS-006）

## 画面（RS-006 で決定）
- 範囲は要件定義書 第4.4章の5画面（質問 `/`・候補者詳細 `/candidates/{id}`・求人票一覧 `/jobs`・求人票詳細 `/jobs/{id}`・履歴 `/history`）。「検索だけ実行」「RAGなし比較」など第5章の機能は RS-007。求人票詳細の「適合理由」（任意）は作っていない
- 実行（外部 API を呼んで queries に記録する操作）は POST にし、記録したら結果の URL へリダイレクトする（POST → リダイレクト → GET）。再読み込みで Claude を二重に呼ばない
  - `POST /`（質問画面）→ `/?query={id}`、`POST /jobs/{id}/match` → `/jobs/{id}?query={id}`、`POST /history/{id}/rerun` → 新しい記録の結果画面
  - RS-004b の `GET /?q=...` での検索はやめた（回答生成と記録が入ったため）
  - 結果の画面は、記録から `QueryLogRestorer` で `RagResult` を組み立て直して表示する。実行直後と履歴の詳細が同じ表示になる。履歴の「詳細」は専用画面を作らず、この結果画面へのリンクにした（`QueryLog::resultUrl()`。元の求人票が消えた job_match は質問画面で表示）
  - 記録に失敗したときだけ、結果をその場で表示して「履歴に残らない」と警告する（RS-005 の方針どおり）
  - 画面からの実行は `Concerns/RunsRagPipeline` にまとめた。`ExternalApiException` は工程（質問のベクトル化 / 回答生成）・ステータス・メッセージを、`InvalidArgumentException`（求人票に見出しがない等）は理由を画面に出す。それ以外（DB のエラー等）は捕まえない
- 質問画面の求人票モードは回答を作らず、`RagPipeline::searchJobs` で mode = job_search として記録する
- `queries.retrieved` に追加した中身: `target`（検索対象の文書数・チャンク数。`Retriever::countTargets`）、`embedding`（質問ベクトルの次元数と先頭3つ）、job_search のときは `candidates` の代わりに `jobs`（document_id ごとのスコア等）。RS-005 で作った記録には target / embedding がないので、その欄は出さない
- 復元のきまり（`QueryLogRestorer`）: スコア・ヒット数は記録した値をそのまま使う。各グループのヒットチャンクは、記録した1段目のチャンクのうち同じ候補者（求人票）で下限以上のもの。本文・見出しは今の chunks から読み、取り込み直しで消えたチャンクは出さずに件数だけ知らせる。類似度は6桁に丸めて記録しているので、下限ちょうど付近では実行時と1件ずれることがある（許容した。reviewer の指摘）
- 候補者詳細・求人票詳細の全文は `DocumentOutline`（純粋クラス）で組み立てる。チャンク本文は全文の部分文字列とは限らない（段落を "\n\n" でつなぐ・先頭に重なりを付ける）ので、全文から位置を探すのではなく「全文から取った見出し」の下に「section が同じチャンク」を chunk_index 順に置き、同じ節の2つ目以降は先頭の重なり（前のチャンクの末尾と一致した部分）を取り除く。1チャンク = 1ブロック（`id="chunk-{id}"`）になり、ハイライトとリンク先にできる
- 候補者詳細は履歴書・職務経歴書の両方の全文を出し、タブの切り替えは画面の JavaScript で行う（サーバーではタブを判定しない。開発者の指示）。開くタブは URL の `#chunk-{id}` を含む文書 → ヒット一覧の先頭（一番近いヒット）を含む文書 → 職務経歴書 の順。JavaScript が動かないときは両方が縦に並ぶ。カードの各チャンク・回答の資料一覧からは `?query={id}#chunk-{id}` で該当箇所へ移動する
- 求人票の「職種名」は本文の「募集職種：」の行（なければタイトルから「求人票：」を除く）、「主な必須スキル」は必須条件の欄の最初の項目。一覧の絞り込みはタイトルの部分一致（LIKE の特殊文字はエスケープ）
- 求人票詳細からの実行は、件数・下限・スコアの決め方を設定の初期値にする（rag:match と同じ）。選べるのは検索に使う部分だけ
- 工程の時間は `RagFormat::STAGES` の順に並べる（jsonb はキーの順番を保たないため。RS-005 の残課題）
- 実行に時間がかかる POST（質問画面・求人票詳細・履歴の再実行）は、フォームに `data-loading="表示する文"` を付ける。送信するとレイアウトの JavaScript が送信ボタンを押せなくし（二重送信で API を2回呼ばない）、スピナーと文を出す。「戻る」でキャッシュから表示されたときは元に戻す（pageshow）
- テストは `tests/Support/FakesRagApis`（埋め込み・Claude の偽装とデータ作成）を共有する

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
