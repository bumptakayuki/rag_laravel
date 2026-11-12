# Development Progress

## Current task
RS-004 検索（done。feature/rs-004-search、未コミット。開発者の動作確認待ち）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）
- RS-001 環境構築: OrbStack 導入、compose.yaml（app: PHP 8.5.12 / db: pgvector/pgvector:pg17）、Laravel 13 を backend/ に作成、pgvector 拡張の有効化マイグレーション、check.sh / init.sh をコンテナ実行に対応。GATE: PASS、reviewer APPROVE
- RS-002 テーブルと分割: candidates / documents / chunks / queries のマイグレーションと Model（Candidate / Document / Chunk / QueryLog）、config/rag.php、app/Services/Rag/Chunker.php（AppServiceProvider で config から bind）、ChunkerTest（Unit）・RagSchemaTest（Feature）。TestCase のガード強化（DB_URL と current_database() も確認）。開発 DB rag に migrate 済み。GATE: PASS（54件）、reviewer APPROVE

- RS-003 取り込み: Embedder（Contracts/Embedder + OpenAiEmbedder。50件バッチ、1s/2s/4s の指数バックオフで3回まで再試行）、ClaudeClient（JSON 生成のみ）、Ingestor、Samples/SampleRoster・SampleGenerator、rag:ingest / rag:chunks / rag:seed-samples。サンプル（候補者20×2 + 求人票10）と eval.json（10問）を生成済み（コミット対象）。開発 DB rag に 50文書・371チャンクを取り込み済み（embedding NULL 0）。GATE: PASS（91件）、reviewer APPROVE

- RS-004 検索: Retriever（生SQL <=>、候補者 / 求人票、--doc-type）、GroupRanker・CandidateRanker（max / avg3 / max_plus_hits）、SearchOptions、JobMatcher（required / required_preferred / full）、rag:search（--type=job 含む）・rag:match。CandidateRankerTest（Unit）・SearchTest（Feature）。実データで done_when 4件を確認（evidence 参照）。GATE: PASS（124件）、reviewer APPROVE（1回目 REJECT → 修正）

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

## Issues / Risks
- [reviewer low] Ingestor: 2つの場所で内容を入れ替えた／移動した場合「同じ内容の文書が既にある」でスキップされ古いデータが残る（Ingestor.php:194-200）。その場合は --fresh で入れ直す。直すなら replace_id を sameContent 判定から外す
- [reviewer low] Ingestor: --fresh で中身が空のディレクトリを指定すると全件削除して0件になる（警告のみ）。$fresh && sources 空なら中止する案
- [reviewer low] Ingestor: 同じ候補者の rirekisho.md と shokumu.md が同じ内容だと片方がスキップされる（生成物では起きない）
- [reviewer low → RS-005] ClaudeClient の再試行待ち時間に rag.embedding.retry_delays_ms を流用している。共通キーか anthropic 側に分ける
- [reviewer low] rag:ingest の --chunk-size に不正値を渡したときのコマンドテストがない（Chunker の検証で FAILURE になる想定）
- RS-004 以降で実 API（質問の埋め込み・回答生成）を使うときは、またキーを backend/.env に置く必要がある
- [reviewer low] DB を使う Unit テストは PHPUnit\Framework\TestCase ではなく Tests\TestCase を継承すること（ガードを効かせるため）
- docker コマンドは OrbStack の PATH（~/.orbstack/bin）が必要。新しいターミナルでは OrbStack が ~/.zprofile に追加した設定で通る想定
- ローカル PHP（Homebrew）は壊れたまま（icu4c）。コンテナで使うので放置。IDE の PHP 補完に必要なら別途対応する

- [reviewer low] Retriever の ORDER BY が距離だけなので、同じ距離のチャンクの並びは決まらない。索引が使われるかは RS-007 の rag:explain で確かめる
- [学習メモ → RS-006 / RS-007] 実データでは「PHPの経験がある人」で PHP 経験の一番長い C001 が5位（max は短いチャンク1つで決まる）。rag:match J01 を full にすると研修だけの C004 が1位（長文で平均化される）。人事の C008（用語だけ知っている）が上位に混ざる。C002（言い換え）は rag:match の上位5名に入らない
- RS-005 で queries に記録するには migration（mode / source_document_id）の追加・実行が必要（承認が必要）

## Next action
- 開発者が RS-004 を動作確認（rag:search / rag:match。API キーを一時的に backend/.env に置く）→ コミット・PR の指示 → RS-005（回答生成）に着手

## Last check
- check.sh: GATE: PASS（2026-11-12、124件。キーを消した後に取り直し）

## Last commit
- 380c543 Merge pull request #3 from bumptakayuki/feature/rs-003-ingest（RS-003）
