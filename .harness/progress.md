# Development Progress

## Current task
RS-002 テーブルと分割（done。開発者の確認待ち・未コミット）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）
- RS-001 環境構築: OrbStack 導入、compose.yaml（app: PHP 8.5.12 / db: pgvector/pgvector:pg17）、Laravel 13 を backend/ に作成、pgvector 拡張の有効化マイグレーション、check.sh / init.sh をコンテナ実行に対応。GATE: PASS、reviewer APPROVE
- RS-002 テーブルと分割: candidates / documents / chunks / queries のマイグレーションと Model（Candidate / Document / Chunk / QueryLog）、config/rag.php、app/Services/Rag/Chunker.php（AppServiceProvider で config から bind）、ChunkerTest（Unit）・RagSchemaTest（Feature）。TestCase のガード強化（DB_URL と current_database() も確認）。開発 DB rag に migrate 済み。GATE: PASS（54件）、reviewer APPROVE

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
- RS-002: 追加した制約: documents.type の CHECK、documents.candidate_id の cascade、chunks (document_id, chunk_index) unique、embedding は NULL 可。queries.prompt / answer も NULL 可

## Issues / Risks
- [reviewer low → RS-003] Chunker はコードブロック（```）内の `# ...` 行も見出し扱いする。サンプルにコードブロックが出るなら対処
- [reviewer low → RS-003] Chunker は section を返すだけ。Ingestor で metadata.section に入ることをテストする
- [reviewer low] DB を使う Unit テストは PHPUnit\Framework\TestCase ではなく Tests\TestCase を継承すること（ガードを効かせるため）
- 未決事項 A-01: ダミーデータ生成（要件定義書は Claude API 生成、CLAUDE.md は Faker ja_JP）→ RS-003 前に決める
- docker コマンドは OrbStack の PATH（~/.orbstack/bin）が必要。新しいターミナルでは OrbStack が ~/.zprofile に追加した設定で通る想定
- ローカル PHP（Homebrew）は壊れたまま（icu4c）。コンテナで使うので放置。IDE の PHP 補完に必要なら別途対応する

## Next action
- 開発者が RS-002 を確認 → コミットの指示 → RS-003（取り込み）の前に A-01（ダミーデータの生成方法）を決める

## Last check
- check.sh: GATE: PASS（2026-11-12、54件）

## Last commit
- c203083（RS-002 の変更は未コミット）
