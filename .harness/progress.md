# Development Progress

## Current task
RS-005 回答生成（done。feature/rs-005-answer、未コミット）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）
- RS-001 環境構築: OrbStack 導入、compose.yaml（app: PHP 8.5.12 / db: pgvector/pgvector:pg17）、Laravel 13 を backend/ に作成、pgvector 拡張の有効化マイグレーション、check.sh / init.sh をコンテナ実行に対応。GATE: PASS、reviewer APPROVE
- RS-002 テーブルと分割: candidates / documents / chunks / queries のマイグレーションと Model（Candidate / Document / Chunk / QueryLog）、config/rag.php、app/Services/Rag/Chunker.php（AppServiceProvider で config から bind）、ChunkerTest（Unit）・RagSchemaTest（Feature）。TestCase のガード強化（DB_URL と current_database() も確認）。開発 DB rag に migrate 済み。GATE: PASS（54件）、reviewer APPROVE

- RS-003 取り込み: Embedder（Contracts/Embedder + OpenAiEmbedder。50件バッチ、1s/2s/4s の指数バックオフで3回まで再試行）、ClaudeClient（JSON 生成のみ）、Ingestor、Samples/SampleRoster・SampleGenerator、rag:ingest / rag:chunks / rag:seed-samples。サンプル（候補者20×2 + 求人票10）と eval.json（10問）を生成済み（コミット対象）。開発 DB rag に 50文書・371チャンクを取り込み済み（embedding NULL 0）。GATE: PASS（91件）、reviewer APPROVE

- RS-004 検索: Retriever（生SQL <=>、候補者 / 求人票、--doc-type）、GroupRanker・CandidateRanker（max / avg3 / max_plus_hits）、SearchOptions、JobMatcher（required / required_preferred / full）、rag:search（--type=job 含む）・rag:match。CandidateRankerTest（Unit）・SearchTest（Feature）。実データで done_when 4件を確認（evidence 参照）。GATE: PASS（124件）、reviewer APPROVE（1回目 REJECT → 修正）
- RS-005 回答生成: PromptBuilder（純粋。[番号] (種別・見出し) 本文、候補者あたり chunks_per_candidate 件）、Prompt / PromptSource、ClaudeClient::generateText、RagPipeline（ask / match。工程ごとに計測・queries に記録・外部 API エラーをログに出す）、RagResult、rag:ask、rag:match を RagPipeline 経由に変更。migration 2026_11_13_000001（queries.mode / source_document_id）を開発 DB rag に実行済み。AskTest（Feature）・PromptBuilderTest（Unit）。GATE: PASS（180件）、reviewer APPROVE（3回目。2回目は REJECT → 修正）。実 API で2問確認済み（queries id=1・2）
- RS-004b 簡易検索画面（開発者の指示で追加）: SearchController（GET /）、layouts/app・search の Blade（Tailwind CDN、<details> で展開）、Retriever::sqlForDisplay()。SearchPageTest（Feature）。開発者がブラウザで実データ確認。GATE: PASS（154件）、reviewer APPROVE（1回目 REJECT → 修正）

## Decisions
- タスクは要件定義書（https://claude.ai/artifact/8SHGJaz6vGQhBTxX14cfsk）第8章の7ステップに合わせた（旧 RS-001〜008 は廃止）
- Docker ランタイムは OrbStack。PHP / Composer はコンテナ内だけで使い、ローカル PHP は修復しない
- 自前の最小 Compose 構成（Sail は使わない）。ポートは app 18080 / db 54320（Docker Desktop が 8000 / 8080 / 5432 を使用中）
- Vite / npm は削除。画面は Blade + Tailwind CDN
- 開発 DB は rag、テスト DB は rag_test。DB 接続先は backend/.env に書き、compose.yaml の environment には書かない
- tests/TestCase.php の setUpTraits() で、設定の DB 名・DB_URL・実際の current_database() を確認し、rag_test 以外ならテストを中止する
- .gitleaks.toml で backend/vendor/ と backend/node_modules/ だけ検査対象から外す
- Laravel が生成した backend/CLAUDE.md（php.new の curl インストール手順）は Docker 用の短いメモに置き換え、AGENTS.md は削除
- RS-002: pgvector/pgvector パッケージは入れず、Laravel 13 本体の Blueprint::vector() と AsVector を使う
- RS-002: Chunker の本文上限は size - overlap（重なり込みで size 以内）。min_size 初期値 150（RAG_CHUNK_MIN_SIZE）はリポジトリで決めた。詳細は docs/architecture.md「データと分割」
- RS-002: Document の種別定数は英語名（TYPE_RESUME / TYPE_CAREER_HISTORY / TYPE_JOB）。値は rirekisho / shokumu / job のまま（PR #2 の指摘）
- RS-002: 追加した制約: documents.type の CHECK、documents.candidate_id の cascade、chunks (document_id, chunk_index) unique、embedding は NULL 可。queries.prompt / answer も NULL 可

- RS-003: A-01 は Claude API 生成に決定（要件定義書どおり）。設計図 SampleRoster で正解と禁止語（RESERVED_WORDS）を先に決め、eval.json も同じ設計図から作る。詳細は docs/architecture.md「取り込みとダミーデータ」
- RS-003: 外部 API は SDK なしで Laravel Http。anthropic-version は現行 2026-10-21 を既定（要件定義書は 2023-06-01）、fallbacks: "default" を付与
- RS-003: テストは phpunit.xml で API キーをダミー値に force、TestCase で Http::preventStrayRequests()
- RS-003: API キーは「使うときだけ backend/.env に書き、終わったら消す」運用（gitleaks が backend/.env を検査するため。設定は緩めない。開発者の判断）
- RS-003: CLAUDE.md のガードレールの「Seeder / Faker ja_JP」を「rag:seed-samples が Claude API で生成」に修正（開発者の指示）
- RS-003: Chunker のコードブロック問題は、生成時にコードブロックを検査で弾く方針で対処（Chunker は変更なし）

- RS-004: 範囲を要件定義書 第8章ステップ4に合わせ、求人票の検索・JobMatcher・rag:match まで含めた（開発者の判断。feature_list の goal / done_when も更新）
- RS-004: max_plus_hits = 最大類似度 + 0.01 × min(ヒット数 − 1, 5)（RAG_HIT_BONUS / RAG_HIT_BONUS_MAX_HITS。開発者の判断）。avg3 は3件未満ならある分だけの平均
- RS-004: 求人票の検索に使う見出しはサンプルに合わせて「必須条件」「歓迎条件」（要件定義書の「必須スキル」）。本文は documents.content から見出しで取る（チャンクは overlap で重複するため）
- RS-004: chunk_limit の上限 1000。ORDER BY は距離のみ（HNSW 索引を使える形）。queries への記録（mode / source_document_id 列の migration を含む）は RS-005 で行う
- RS-004b: RS-006 の質問画面（/）の検索部分だけを先に作った。回答・プロンプト・記録は RS-005、求人票詳細へのリンクや他画面は RS-006。詳細は docs/architecture.md「簡易検索画面」

- RS-005: 記録するのは rag:ask（question）と rag:match（job_match）だけ（開発者の判断）。rag:search と画面は記録しない。job_search の記録は RS-006 の画面で行う
- RS-005: 資料が0件なら Claude を呼ばず「資料からはわかりません」を返して記録する。外部 API が失敗した実行は記録しない
- RS-005: queries.source_document_id は nullOnDelete（求人票を入れ直しても履歴は残す）。mode は CHECK 制約、既定 question
- RS-005: ClaudeClient の再試行の待ち時間を rag.anthropic.retry_delays_ms に分けた（RS-003 の reviewer low を解消）
- RS-005: 外部 API の失敗は ExternalApiException（stage つき）に包み、コマンドはこれだけを捕まえる（DB エラーは取り違えないようそのまま投げる）
- RS-005: 記録に失敗しても回答は表示してから FAILURE。理由は PDO メッセージの1行目だけ（SQL のバインド値や DETAIL の行の値＝プロンプト・回答の全文を出さない）
- 詳細は docs/architecture.md「回答生成と記録」

## Issues / Risks
- [reviewer low] Ingestor: 2つの場所で内容を入れ替えた／移動した場合「同じ内容の文書が既にある」でスキップされ古いデータが残る（Ingestor.php:194-200）。その場合は --fresh で入れ直す。直すなら replace_id を sameContent 判定から外す
- [reviewer low] Ingestor: --fresh で中身が空のディレクトリを指定すると全件削除して0件になる（警告のみ）。$fresh && sources 空なら中止する案
- [reviewer low] Ingestor: 同じ候補者の rirekisho.md と shokumu.md が同じ内容だと片方がスキップされる（生成物では起きない）
- [reviewer low] rag:ingest の --chunk-size に不正値を渡したときのコマンドテストがない（Chunker の検証で FAILURE になる想定）
- RS-004 以降で実 API（質問の埋め込み・回答生成）を使うときは、またキーを backend/.env に置く必要がある
- [reviewer low] DB を使う Unit テストは PHPUnit\Framework\TestCase ではなく Tests\TestCase を継承すること（ガードを効かせるため）
- docker コマンドは OrbStack の PATH（~/.orbstack/bin）が必要。新しいターミナルでは OrbStack が ~/.zprofile に追加した設定で通る想定
- ローカル PHP（Homebrew）は壊れたまま（icu4c）。コンテナで使うので放置。IDE の PHP 補完に必要なら別途対応する

- [reviewer low] Retriever の ORDER BY が距離だけなので、同じ距離のチャンクの並びは決まらない。索引が使われるかは RS-007 の rag:explain で確かめる
- [学習メモ → RS-006 / RS-007] 実データでは「PHPの経験がある人」で PHP 経験の一番長い C001 が5位（max は短いチャンク1つで決まる）。rag:match J01 を full にすると研修だけの C004 が1位（長文で平均化される）。人事の C008（用語だけ知っている）が上位に混ざる。C002（言い換え）は rag:match の上位5名に入らない
- [reviewer low → RS-006] SearchController で Embedder が例外（API 障害など）を出すと 500 になる。RS-005 で RagPipeline はログに出して投げ直す形にした。画面では RS-006 で RagPipeline を使い、エラーを表示する
- [reviewer low] ?doc_type[]=x は「絞り込みなし」で検索が進む（500 にはならない）。テストは足していない
- [reviewer low] RagPipeline::record（214行目付近）の境界: QueryException に previous がないと自身のメッセージ（SQL とバインド値を含みうる）の1行目を使う／メッセージが空だと strtok が false を返し TypeError。どちらも通常は起きない。直すなら previous なしは固定文、`strtok(...) ?: '不明なエラー'`
- [学習メモ → RS-007] 実データの「PHPの経験がある人は？」で、Laravel を1件だけ経験した C017 の資料も渡したが、回答には挙がらなかった（資料の選び方と回答の判断は別）
- queries の jsonb はキーの順番を保たない。latency_ms を工程順に見せるには表示側で順番を決める（RS-006）

## Next action
- 開発者が RS-005 の差分を確認 → 指示があればコミット・PR → RS-006（画面）

## Last check
- check.sh: GATE: PASS（2026-11-13、180件）

## Last commit
- afb5868 Merge pull request #4（RS-004）
