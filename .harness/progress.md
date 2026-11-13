# Development Progress

## Current task
なし（RS-008〜RS-010 完了。全タスク done）

## Completed
- ハーネス最小構成を作成（CLAUDE.md / feature_list.json / progress.md / init.sh / check.sh / reviewer / settings.json）
- RS-001 環境構築: OrbStack 導入、compose.yaml（app: PHP 8.5.12 / db: pgvector/pgvector:pg17）、Laravel 13 を backend/ に作成、pgvector 拡張の有効化マイグレーション、check.sh / init.sh をコンテナ実行に対応。GATE: PASS、reviewer APPROVE
- RS-002 テーブルと分割: candidates / documents / chunks / queries のマイグレーションと Model（Candidate / Document / Chunk / QueryLog）、config/rag.php、app/Services/Rag/Chunker.php（AppServiceProvider で config から bind）、ChunkerTest（Unit）・RagSchemaTest（Feature）。TestCase のガード強化（DB_URL と current_database() も確認）。開発 DB rag に migrate 済み。GATE: PASS（54件）、reviewer APPROVE

- RS-003 取り込み: Embedder（Contracts/Embedder + OpenAiEmbedder。50件バッチ、1s/2s/4s の指数バックオフで3回まで再試行）、ClaudeClient（JSON 生成のみ）、Ingestor、Samples/SampleRoster・SampleGenerator、rag:ingest / rag:chunks / rag:seed-samples。サンプル（候補者20×2 + 求人票10）と eval.json（10問）を生成済み（コミット対象）。開発 DB rag に 50文書・371チャンクを取り込み済み（embedding NULL 0）。GATE: PASS（91件）、reviewer APPROVE

- RS-004 検索: Retriever（生SQL <=>、候補者 / 求人票、--doc-type）、GroupRanker・CandidateRanker（max / avg3 / max_plus_hits）、SearchOptions、JobMatcher（required / required_preferred / full）、rag:search（--type=job 含む）・rag:match。CandidateRankerTest（Unit）・SearchTest（Feature）。実データで done_when 4件を確認（evidence 参照）。GATE: PASS（124件）、reviewer APPROVE（1回目 REJECT → 修正）
- RS-005 回答生成: PromptBuilder（純粋。[番号] (種別・見出し) 本文、候補者あたり chunks_per_candidate 件）、Prompt / PromptSource、ClaudeClient::generateText、RagPipeline（ask / match。工程ごとに計測・queries に記録・外部 API エラーをログに出す）、RagResult、rag:ask、rag:match を RagPipeline 経由に変更。migration 2026_11_13_000001（queries.mode / source_document_id）を開発 DB rag に実行済み。AskTest（Feature）・PromptBuilderTest（Unit）。GATE: PASS（180件）、reviewer APPROVE（3回目。2回目は REJECT → 修正）。実 API で2問確認済み（queries id=1・2）
- RS-006 画面: 要件定義書 第4.4章の5画面（/・/candidates/{id}・/jobs・/jobs/{id}・/history）。質問画面を PRG にし QueryLogRestorer で記録から結果を復元、DocumentOutline で全文表示、候補者詳細のタブは JavaScript（開発者の指示）、時間のかかる POST にスピナーと二重送信防止（開発者の指示）、履歴の再実行。CandidatePageTest・JobPagesTest・HistoryPageTest・DocumentOutlineTest、SearchPageTest を POST に書き換え。GATE: PASS（223件）、reviewer APPROVE（1回目 REJECT＝gitleaks のみ → .env のキー削除後 2回目 APPROVE）。goal は開発者の承認で5画面に変更。開発者がブラウザで確認済み
- RS-004b 簡易検索画面（開発者の指示で追加）: SearchController（GET /）、layouts/app・search の Blade（Tailwind CDN、<details> で展開）、Retriever::sqlForDisplay()。SearchPageTest（Feature）。開発者がブラウザで実データ確認。GATE: PASS（154件）、reviewer APPROVE（1回目 REJECT → 修正）
- RS-007 実験機能: 質問画面の「検索だけ実行」（params.search_only、Claude を呼ばない）と「RAGなし比較」（queries.answer_without_rag。migration 2026_11_13_000002 を開発 DB に実行済み）、rag:eval（Evaluator）・rag:index（VectorIndex）・rag:explain（Retriever::explain）・rag:seed-random（RandomChunkSeeder）、結合テスト EndToEndTest、README。GATE: PASS（253件）、reviewer APPROVE（2回。どちらも APPROVE）。実データの rag:eval は Recall@5 1.000・平均順位 1.81
- RS-008 候補者一覧（開発者の指示で追加）: GET /candidates（CandidateController::index）、コード順の全候補者、コード・名前の部分一致で絞り込み、文書の有無・チャンク数（Candidate::chunks() HasManyThrough）・取り込み日時。ナビに「候補者」を追加し、候補者詳細ではこちらを選択中にした（以前は「検索」）。CandidateListPageTest。GATE: PASS（260件）、reviewer APPROVE（1回目 REJECT → 修正）
- RS-010 索引テストの安定化（開発者の指示で RS-009 より先に実施）: ExperimentCommandsTest の「planner が全件読みを選んだとき」のテストで、chunks が document_id の一意索引を使う Bitmap Heap Scan になり、ときどき落ちていた。--set に enable_bitmapscan=off を追加。全体実行10回・check.sh 3回すべて PASS。reviewer APPROVE
- RS-009 候補者一覧のキーワード検索（開発者の指示で追加）: /candidates?q=&job=&skill=&keyword=。希望職種（「希望職種：」の行。職務経歴書→履歴書）はプルダウンから選び完全一致、スキルは「## スキル」欄に含まれる（一致した行を表示）、キーワードは全文に含まれる。大文字小文字は区別しない。条件はすべて満たすものだけ出す。判定は PHP（CandidateController::desiredJob / matchingSkillLines）。GATE: PASS（265件）、reviewer APPROVE（1回目 REJECT → RS-010 と修正）

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

- RS-006: 実行は POST → 記録 → 結果の URL へリダイレクト。結果は記録から QueryLogRestorer で組み立て直す（履歴の詳細も同じ画面）。GET /?q= の検索はやめた
- RS-006: 質問画面の求人票モードは回答なしで mode = job_search として記録。retrieved に target / embedding / jobs を追加
- RS-006: 全文表示は DocumentOutline（見出し + 重なりを除いたチャンク本文）。候補者詳細のタブは画面の JavaScript で切り替える（サーバーは ?tab= を見ない。開発者の指示）
- RS-006: 求人票詳細からの実行は設定の初期値で、選べるのは検索に使う部分だけ。適合理由は作らない（任意）
- 詳細は docs/architecture.md「画面」

- RS-007: Recall@k は check.sh に入れない（A-02。実 API が必要なため。開発者の判断）。RAGなし比較の回答は queries の新しい列に保存（開発者の判断）
- RS-007: 「検索だけ実行」は mode を増やさず question + params.search_only。押せなくしたボタンの値は送られないので、レイアウトの JS で hidden に写す
- RS-007: RAGなしの回答の生成に失敗したら、RAGありの回答も含めて記録しない（外部 API の失敗は記録しない方針どおり。課金済みの回答は失われる。reviewer low）
- RS-007: rag:explain の設定（--set）は set_config(…, true) で、トランザクションは必ず巻き戻す（セーブポイントの確定では SET LOCAL が残るため）
- RS-007: 索引は開発 DB 3万件・既定 ef_search=40 では planner が使わない。比較は --set=hnsw.ef_search=60 で行う。Retriever は ef_search を変えない（学習の題材）
- 詳細は docs/architecture.md「実験機能」

- RS-009: 「完全一致で良い」の解釈は、希望職種=完全一致、スキル・キーワード=含む（開発者の判断。Java で JavaScript にも当たる）
- RS-008〜RS-010 はまとめて1コミット（開発者の指示）

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
- [reviewer low] ?doc_type[]=x は「絞り込みなし」で検索が進む（500 にはならない）。テストは足していない
- [reviewer low] RagPipeline::record（214行目付近）の境界: QueryException に previous がないと自身のメッセージ（SQL とバインド値を含みうる）の1行目を使う／メッセージが空だと strtok が false を返し TypeError。どちらも通常は起きない。直すなら previous なしは固定文、`strtok(...) ?: '不明なエラー'`
- [学習メモ → RS-007] 実データの「PHPの経験がある人は？」で、Laravel を1件だけ経験した C017 の資料も渡したが、回答には挙がらなかった（資料の選び方と回答の判断は別）
- [reviewer low] candidates/show の tabOf / openHash の decodeURIComponent は `#%` のような壊れたハッシュで URIError を投げ、タブが初期化されない（両パネル表示のまま。崩れはしない）。直すなら try/catch で null を返す
- [reviewer low] ローカルの main が origin/main より古い（RS-005 のマージが未反映）。差分やレビュー範囲は origin/main 基準で見る
- [reviewer low] タブ・スピナーの JS は属性の有無しかテストしていない（開発者がブラウザで確認済み）
- [reviewer medium] ExperimentCommandsTest の索引テストは「既定 ef_search のまま planner が Index Scan を選び 40 行返る」を前提にしている。planner の判断は件数・テーブルの大きさ（ロールバックした行のページも残る）・バージョンで変わるので、不安定になったら索引ありの側を --set=enable_seqscan=off か hnsw.ef_search=60 で確かめ、「40 行だけ返る」は別のテストに分ける
- [reviewer low] rag:explain の説明文「LIMIT が hnsw.ef_search より大きいときに起きる」は言い切りすぎ（テストでは LIMIT 50 でも索引が選ばれる）。直すなら「起きることがある」
- [reviewer low] eval.json の expected に存在しない候補者コード（打ち間違い）があっても、黙って「圏外」になる。直すなら警告を出す
- [reviewer low] rag:seed-random が途中で失敗するとバッチ単位で部分的に残る。残っている間は候補者 RANDOM が画面に出ることがある → --purge で消す
- [reviewer low] 「検索だけ実行」ボタンの JS（押したボタンの値を hidden に写す）は自動テストしていない（開発者がブラウザで確認済み）
- [reviewer low] 候補者一覧はページングなしで全件出す。rag:seed-random の候補者 RANDOM も1行として出る（チャンク数は withCount なので重くはない）
- [reviewer low] 候補者一覧は、職種の選択肢を作るために全候補者の文書の content をもう1回読み込む（1回のリクエストで2回）。候補者が増えたら選択肢をキャッシュするか、1回の読み込みにまとめる
- [reviewer low] rag:explain は HNSW 索引を使わずに Bitmap Heap Scan（document_id の索引）になったときも「chunks を全件読み」と出す。直すなら Seq Scan かどうかを別に判定して文言を変える
- [RS-010] 索引ありの側のテスト（test_rag_index_の前後で…、planner が Index Scan を選ぶ前提）は今回落ちていないが、上の reviewer medium の懸念は残る

## Next action
- 開発者がブラウザで /candidates の絞り込みを確認する。push・PR 作成は指示を待つ

## Last check
- check.sh: GATE: PASS（2026-11-13、265件。3回続けて PASS）

## Last commit
- RS-008〜RS-010: feature/rs-008-candidate-list に1コミット（開発者の指示）。push・PR は未実施
- RS-007: feature/rs-007-experiments をコミットし、PR でマージ（開発者の指示）
