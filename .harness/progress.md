# Development Progress

## Current task
RS-001 Laravel 雛形と品質ゲートの有効化（未着手）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）

## Decisions
- Laravel は `backend/` に置く（ハーネスのファイルと分けるため）
- 候補者データは架空データのみ
- Embedding / LLM はインターフェース経由にし、テストは Fake 実装で行う

## Issues / Risks
- ローカルの PHP が起動しない: `php -v` が `Library not loaded: /usr/local/opt/icu4c/lib/libicuio.74.dylib` で失敗する（Homebrew の icu4c 更新による不整合と思われる）。修復には開発者の承認が必要
- 未決事項 A-01（DB）、A-02（ベクトル検索方式）、A-03（Embedding / LLM プロバイダー）は docs/architecture.md を参照

## Next action
- 開発者が PHP の修復方法を承認する（例: `brew reinstall php` または `brew link` のやり直し）→ `bash scripts/init.sh` が通ることを確認 → RS-001 に着手

## Last check
- check.sh: GATE: NOT CONFIGURED（backend/ が未作成）

## Last commit
- なし
