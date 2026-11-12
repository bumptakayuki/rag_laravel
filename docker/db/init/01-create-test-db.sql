-- テスト用 DB（php artisan test が使う）。本番相当の rag DB とは分ける。
-- vector 拡張は各 DB の最初のマイグレーションで有効化する（要件定義書 第3章）。
CREATE DATABASE rag_test OWNER rag;
