# Candidate Search RAG — 開発ルール

Laravel で候補者を自然文で検索できる簡易 RAG アプリ。開発者1人 + Claude Code で開発する。
このファイルは「地図」。詳細は参照先を読むこと。

## 構成
- アプリ本体: `backend/`（Laravel 13）。実行は Docker Compose（`compose.yaml`、PHP はコンテナ内のみ）
- 要件定義書（正本）: https://claude.ai/artifact/8SHGJaz6vGQhBTxX14cfsk
- 設計: `docs/architecture.md`
- タスクと受け入れ条件: `.harness/feature_list.json`
- セッション引き継ぎ: `.harness/progress.md`
- 品質ゲート: `scripts/check.sh`（最後に `GATE: PASS` が出たときだけ合格）
- 環境確認: `scripts/init.sh`
- 独立レビュー: `.claude/agents/reviewer.md`（`reviewer` サブエージェント）

## セッション開始時
1. `.harness/progress.md` と `.harness/feature_list.json` を読む（SessionStart Hook が要約を出す）
2. `git status` に未コミット変更があれば、手を付ける前に開発者へ報告する（勝手に reset / checkout しない）
3. `bash scripts/init.sh` で環境を確認する

## 1タスクの進め方
1. `feature_list.json` から `pending` のタスクを **1つだけ** 選び、`goal` と `done_when` を確認する
2. 変更するファイルと計画を提示する。`approval_required` に当たる操作は承認を待つ
3. 小さく実装し、`done_when` ごとにテストを書く
4. `bash scripts/check.sh` を実行する
5. `reviewer` サブエージェントにレビューを依頼する。REJECT なら指摘箇所だけ直して 4 からやり直す
6. `GATE: PASS` かつ reviewer が APPROVE のときだけ、`status: "done"`、`passes: true`、`evidence` を記入する
7. `.harness/progress.md` を更新する（現在の状態・決定事項・残課題・次の1アクション）
8. コミットは開発者の指示があったときだけ行う

## ガードレール
- 対象タスクに関係ないコードを変更しない。大規模リファクタリングをしない
- テストを削除・skip・弱体化して合格扱いにしない。`check.sh` を書き換えてゲートを緩めない
- 未実行のチェックを「成功」と報告しない。「完了した」と言うのは `GATE: PASS` を確認した後だけ
- 同じ失敗が2回続いたら方針を見直す。修正は最大3回で止め、原因・試したこと・次の案を progress.md に書いて開発者に渡す
- 候補者データは **架空データのみ**（Seeder / Faker ja_JP）。実在人物のデータを repo やプロンプトに入れない
- `.env` や API キーを読まない・表示しない・ログに出さない
- 外部 LLM / Embedding API への送信、`composer require` / `npm install`、migration の実行、`git push` は承認が必要
- 本番環境・本番 DB には触れない

`CLAUDE.md` は指示であってアクセス制御ではない。実際の制限は `.claude/settings.json` で強制している。
