# Development Progress

## Current task
RS-001 環境構築（done。ブラウザ表示も開発者が確認済み）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）
- RS-001 環境構築: OrbStack 導入、compose.yaml（app: PHP 8.5.12 / db: pgvector/pgvector:pg17）、Laravel 13 を backend/ に作成、pgvector 拡張の有効化マイグレーション、check.sh / init.sh をコンテナ実行に対応。GATE: PASS、reviewer APPROVE

## Decisions
- タスクは要件定義書（https://claude.ai/artifact/8SHGJaz6vGQhBTxX14cfsk）第8章の7ステップに合わせた（旧 RS-001〜008 は廃止）
- Docker ランタイムは OrbStack。PHP / Composer はコンテナ内だけで使い、ローカル PHP は修復しない
- 自前の最小 Compose 構成（Sail は使わない）。ポートは app 18080 / db 54320（Docker Desktop が 8000 / 8080 / 5432 を使用中）
- Vite / npm は削除。画面は Blade + Tailwind CDN
- 開発 DB は rag、テスト DB は rag_test。DB 接続先は backend/.env に書き、compose.yaml の environment には書かない
- tests/TestCase.php の setUpTraits() で、接続先が rag_test 以外ならテストを中止する（開発 DB を消さないため）
- .gitleaks.toml で backend/vendor/ と backend/node_modules/ だけ検査対象から外す
- Laravel が生成した backend/CLAUDE.md（php.new の curl インストール手順）は Docker 用の短いメモに置き換え、AGENTS.md は削除

## Issues / Risks
- [reviewer medium] TestCase のガードは config の database しか見ていない。実環境変数 DB_URL や DB_HOST で別の DB を指すとすり抜ける → RS-002 の前に、url が空でなければ中止する or current_database() を確認する
- [reviewer low] DB を使う Unit テストは PHPUnit\Framework\TestCase ではなく Tests\TestCase を継承すること（ガードを効かせるため）
- 未決事項 A-01: ダミーデータ生成（要件定義書は Claude API 生成、CLAUDE.md は Faker ja_JP）→ RS-003 前に決める
- 一度、テストが開発 DB rag で走っていた（compose.yaml の environment が原因）。DB が空だったので損失はなし。修正済み
- docker コマンドは OrbStack の PATH（~/.orbstack/bin）が必要。新しいターミナルでは OrbStack が ~/.zprofile に追加した設定で通る想定
- ローカル PHP（Homebrew）は壊れたまま（icu4c）。コンテナで使うので放置。IDE の PHP 補完に必要なら別途対応する

## Next action
- 開発者が backend/.env の APP_URL を http://localhost:18080 に直す → コミットの指示 → RS-002（テーブルと分割）に着手。着手前に TestCase のガード強化（上の medium）

## Last check
- check.sh: GATE: PASS（2026-11-12 18:18）

## Last commit
- e920f7f（RS-001 の変更は未コミット）
