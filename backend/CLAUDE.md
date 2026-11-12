# backend/（Laravel 本体）

ルールはリポジトリ直下の `CLAUDE.md` に従う。ここでは実行方法だけ書く。

- PHP / Composer はローカルに入れない。すべて app コンテナで実行する（PHP 8.5）
  - 例: `docker compose exec app php artisan test`（リポジトリ直下で実行）
- DB は db コンテナの PostgreSQL + pgvector。テストは `rag_test` DB を使う
