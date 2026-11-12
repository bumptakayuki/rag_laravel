<?php

namespace App\Services\Rag\Samples;

/**
 * ダミーデータの設計図（要件定義書 第3章「ダミーデータ」）。すべて架空。
 *
 * 生成する前に「どの候補者がどの条件に当てはまるか」をここで決め、
 * Claude にはこの設定どおりに文章を書かせる。評価データ（eval.json）も同じ設定から作る。
 *
 * 類似度の差を観察できるよう、次の幅を持たせている。
 *   - 職種: バックエンド / フロントエンド / インフラ / データ / 営業 / 人事 / ほか
 *   - 経験年数: 1〜15年
 *   - 同じスキルの熟練度: 主力として長く使った人 / 一部だけ触った人 / 研修で学んだだけの人
 *   - 言い換え: C002 は Laravel を「PHP のフレームワーク」とだけ書く。C013 は Kubernetes を名前で書かない
 */
final class SampleRoster
{
    /**
     * 評価の質問で使う語のうち、正解の候補者以外の文書に出てほしくない語。
     * 正解でない候補者の生成時に「使ってはいけない語」として渡し、生成後にも検査する。
     * 言い換えの候補者（C002・C013）も、ここで名前を書かないようにしている。
     *
     * @var list<array{words: list<string>, allowed: list<string>}>
     */
    public const RESERVED_WORDS = [
        ['words' => ['Laravel', 'ララベル'], 'allowed' => ['C001', 'C003', 'C004', 'C017']],
        ['words' => ['Kubernetes', 'k8s', 'K8s', 'GKE', 'EKS', 'AKS', 'クバネティス'], 'allowed' => ['C005']],
        ['words' => ['機械学習', 'ディープラーニング', '深層学習'], 'allowed' => ['C014']],
        ['words' => ['Vue', 'Nuxt'], 'allowed' => ['C012']],
        ['words' => ['エンジニア採用', '技術者採用'], 'allowed' => ['C008']],
    ];

    /**
     * 候補者20名。facts は文書に必ず書く事実、avoid は書かないこと（追加の禁止語は RESERVED_WORDS から自動で足す）。
     *
     * @return list<array{code: string, name: string, role: string, years: int, facts: list<string>, avoid?: list<string>}>
     */
    public static function candidates(): array
    {
        return [
            [
                'code' => 'C001', 'name' => '青木 健太', 'role' => 'バックエンドエンジニア', 'years' => 10,
                'facts' => [
                    'PHP と Laravel を主力として8年以上、業務システムと自社 Web サービスの開発に使ってきた',
                    'Laravel のバージョンアップ、キュー処理、API 設計、テストコードの整備を主導した',
                    'MySQL のテーブル設計と遅いクエリの改善が得意',
                    'AWS は EC2 と RDS を使う程度（設計の主担当ではない）',
                    '5名のチームのリーダー経験あり',
                ],
            ],
            [
                'code' => 'C002', 'name' => '石川 美咲', 'role' => 'バックエンドエンジニア', 'years' => 6,
                'facts' => [
                    'PHP を主力として6年。業務では「PHP のフレームワーク」「PHP の MVC フレームワーク」を使って EC サイトと予約システムを開発した',
                    'フレームワークの製品名は一度も書かない（「PHPのフレームワーク」とだけ書く）',
                    'ORM を使ったデータベース設計、決済サービスとの連携、管理画面の開発を担当',
                    'PostgreSQL を使用',
                ],
            ],
            [
                'code' => 'C003', 'name' => '上田 翔太', 'role' => 'フロントエンドエンジニア', 'years' => 4,
                'facts' => [
                    'TypeScript と React を主力として4年、SaaS の管理画面を開発',
                    'デザインシステムの整備とコンポーネントのテストを担当',
                    'バックエンドが Laravel のプロジェクトで、Blade テンプレートの修正と簡単な API 追加を半年ほど手伝った（一部だけ触った程度）',
                ],
            ],
            [
                'code' => 'C004', 'name' => '江口 さくら', 'role' => 'バックエンドエンジニア（未経験から転職）', 'years' => 1,
                'facts' => [
                    '前職は事務職。転職後1年',
                    '入社時の3か月の研修で PHP と Laravel の基礎を学び、研修課題として簡単な掲示板アプリを作った（実務での Laravel 経験はない）',
                    '現在は社内ツールの軽微な修正とテスト作業を担当',
                    '基本情報技術者試験に合格',
                ],
            ],
            [
                'code' => 'C005', 'name' => '大野 誠', 'role' => 'インフラエンジニア / SRE', 'years' => 12,
                'facts' => [
                    'AWS を主力として10年、VPC・EC2・RDS・ECS などを使った本番環境の設計・構築・運用を担当',
                    'Terraform によるインフラのコード化を導入',
                    'Kubernetes（Amazon EKS）でコンテナ基盤を3年間運用し、監視とオートスケールを整備',
                    'Linux サーバーの運用、障害対応、オンコール体制づくりの経験',
                    'AWS 認定ソリューションアーキテクト（プロフェッショナル）を保有',
                ],
            ],
            [
                'code' => 'C006', 'name' => '加藤 由美', 'role' => 'データエンジニア', 'years' => 7,
                'facts' => [
                    'Python と SQL を主力として、データパイプラインを設計・構築してきた',
                    'Airflow でバッチ処理を管理し、BigQuery にデータウェアハウスを構築',
                    '営業部門向けのダッシュボード作成と、データ品質のチェック自動化を担当',
                ],
            ],
            [
                'code' => 'C007', 'name' => '木村 拓也', 'role' => '法人営業', 'years' => 9,
                'facts' => [
                    'IT 企業で業務用 SaaS の法人営業を9年',
                    '新規開拓と既存顧客の深耕、年間売上目標の達成実績',
                    '提案書作成、商談、契約までを一貫して担当',
                    'プログラミングの経験はない',
                ],
            ],
            [
                'code' => 'C008', 'name' => '久保 早紀', 'role' => '人事（採用担当）', 'years' => 5,
                'facts' => [
                    'IT 企業の人事で、ソフトウェアエンジニアの採用（エンジニア採用）を5年担当',
                    '求人票の作成、スカウト、面接の調整、採用イベントの企画を担当',
                    '求人票を書くため PHP や AWS などの用語は知っているが、自分で開発した経験はない',
                ],
            ],
            [
                'code' => 'C009', 'name' => '小林 浩二', 'role' => 'バックエンドエンジニア', 'years' => 15,
                'facts' => [
                    'Java と Spring Boot を主力として12年、金融機関向けの基幹システムを開発',
                    'キャリアの最初の2年間は、フレームワークを使わない素の PHP で小規模な Web サイトを作った（古い経験）',
                    'Oracle Database と性能チューニングの経験',
                    '設計書のレビューと若手の育成を担当',
                ],
            ],
            [
                'code' => 'C010', 'name' => '斉藤 陽菜', 'role' => 'モバイルアプリエンジニア', 'years' => 5,
                'facts' => [
                    'Swift で iOS アプリ、Kotlin で Android アプリを開発（5年）',
                    'ストアへの公開と、クラッシュの調査・改善を担当',
                ],
            ],
            [
                'code' => 'C011', 'name' => '清水 大輔', 'role' => 'バックエンドエンジニア', 'years' => 3,
                'facts' => [
                    'Go を主力として3年、マイクロサービスの API を開発',
                    'Docker でローカル開発環境を整備し、gRPC でサービス間通信を実装',
                    'コンテナの本番運用はインフラチームの担当で、自分は関わっていない',
                ],
            ],
            [
                'code' => 'C012', 'name' => '菅原 彩', 'role' => 'フロントエンドエンジニア', 'years' => 8,
                'facts' => [
                    'Vue.js と Nuxt を主力として8年、メディアサイトと EC サイトのフロントエンドを開発',
                    '表示速度の改善とアクセシビリティ対応を担当',
                    'JavaScript から TypeScript への移行を主導',
                ],
            ],
            [
                'code' => 'C013', 'name' => '関 直樹', 'role' => 'SRE', 'years' => 6,
                'facts' => [
                    'Google Cloud を使った本番環境の運用を6年',
                    'コンテナオーケストレーション基盤（Google Cloud のマネージドサービス）で数十のサービスを運用し、マニフェストの管理とデプロイの自動化を担当',
                    'その基盤の製品名・略称は一度も書かない（「コンテナオーケストレーション基盤」とだけ書く）',
                    'Prometheus と Grafana で監視を整備',
                ],
            ],
            [
                'code' => 'C014', 'name' => '高田 莉子', 'role' => 'データサイエンティスト', 'years' => 4,
                'facts' => [
                    'Python で機械学習モデル（需要予測・離反予測）を開発し、本番に組み込んだ',
                    'scikit-learn と LightGBM を使用。統計検定2級',
                    '分析結果を経営層に説明するレポートを作成',
                ],
            ],
            [
                'code' => 'C015', 'name' => '千葉 修', 'role' => 'プロジェクトマネージャー', 'years' => 11,
                'facts' => [
                    'Web システム開発のプロジェクトマネージャーを8年。予算・進捗・品質の管理',
                    'PM になる前の3年間はプログラマーとして、フレームワークを使わない PHP で受託開発をした',
                    'PMP を保有',
                ],
            ],
            [
                'code' => 'C016', 'name' => '土屋 恵', 'role' => 'QA エンジニア', 'years' => 5,
                'facts' => [
                    'Web アプリのテスト設計とテスト自動化を5年',
                    'Playwright と Selenium で E2E テストを自動化',
                    'JSTQB Foundation Level を保有',
                ],
            ],
            [
                'code' => 'C017', 'name' => '中西 悠', 'role' => 'バックエンドエンジニア', 'years' => 2,
                'facts' => [
                    'Ruby on Rails を主力として2年、自社サービスの開発',
                    '副業で Laravel を使った小さな案件を1件だけ手伝った（一部だけ触った程度）',
                ],
            ],
            [
                'code' => 'C018', 'name' => '野口 愛', 'role' => 'カスタマーサクセス', 'years' => 3,
                'facts' => [
                    '外資系 SaaS 企業で、海外の顧客を英語で支援するカスタマーサクセスを3年',
                    '英語での商談・オンボーディング・問い合わせ対応。TOEIC 920点',
                    '大学時代に1年間の海外留学',
                ],
            ],
            [
                'code' => 'C019', 'name' => '浜田 和子', 'role' => '人事（労務・制度）', 'years' => 13,
                'facts' => [
                    '人事として労務管理、給与計算、評価制度の設計を13年',
                    '社会保険労務士の資格を保有',
                    '採用業務の担当経験はない',
                ],
            ],
            [
                'code' => 'C020', 'name' => '藤井 蓮', 'role' => 'インフラエンジニア（新卒）', 'years' => 1,
                'facts' => [
                    '新卒入社1年目',
                    '新人研修で AWS の基礎（EC2 の起動、S3 の使い方）を学んだだけで、本番環境の構築経験はない',
                    '現在は先輩の指示で Linux サーバーのログ確認と手順書の更新を担当',
                    'AWS 認定クラウドプラクティショナーを保有',
                ],
            ],
        ];
    }

    /**
     * 求人票10件（候補者検索の対象ではないが、rag:search --type=job で使う）。
     *
     * @return list<array{slug: string, title: string, facts: list<string>}>
     */
    public static function jobs(): array
    {
        return [
            ['slug' => 'J01-backend-php', 'title' => 'バックエンドエンジニア（PHP / Laravel）', 'facts' => ['自社 SaaS の API 開発', 'PHP と Laravel の実務経験3年以上が必須']],
            ['slug' => 'J02-frontend-react', 'title' => 'フロントエンドエンジニア（React）', 'facts' => ['管理画面の開発', 'TypeScript と React の実務経験が必須']],
            ['slug' => 'J03-sre-aws', 'title' => 'SRE（AWS）', 'facts' => ['本番環境の設計・運用', 'AWS での構築経験とコンテナ基盤の運用経験']],
            ['slug' => 'J04-data-engineer', 'title' => 'データエンジニア', 'facts' => ['データ基盤の構築', 'Python と SQL の経験']],
            ['slug' => 'J05-sales', 'title' => '法人営業（SaaS）', 'facts' => ['中堅企業向けの新規開拓', 'IT 業界での法人営業経験']],
            ['slug' => 'J06-hr-recruiter', 'title' => '人事（エンジニア採用）', 'facts' => ['エンジニア採用の企画と実行', '採用担当の経験']],
            ['slug' => 'J07-backend-go', 'title' => 'バックエンドエンジニア（Go）', 'facts' => ['マイクロサービスの開発', 'Go の実務経験']],
            ['slug' => 'J08-mobile', 'title' => 'モバイルアプリエンジニア', 'facts' => ['iOS / Android アプリの開発', 'Swift または Kotlin の経験']],
            ['slug' => 'J09-qa', 'title' => 'QA エンジニア', 'facts' => ['テスト設計と自動化', 'E2E テスト自動化の経験']],
            ['slug' => 'J10-pm', 'title' => 'プロジェクトマネージャー（Web）', 'facts' => ['受託開発のプロジェクト管理', 'PM 経験3年以上']],
        ];
    }

    /**
     * rag:eval 用の評価データ（10問）。expected は「上位 k 名に入ってほしい候補者コード」。
     *
     * @return list<array{question: string, expected: list<string>, note: string}>
     */
    public static function evalQuestions(): array
    {
        return [
            ['question' => 'PHPのフレームワークを使った業務開発の経験が長い人', 'expected' => ['C001', 'C002'], 'note' => 'C002 はフレームワーク名を書いていない（言い換え）'],
            ['question' => 'Laravelを使ったことがある人', 'expected' => ['C001', 'C002', 'C003', 'C004', 'C017'], 'note' => 'C002 は「PHPのフレームワーク」とだけ書く。C003・C017 は一部、C004 は研修のみ'],
            ['question' => 'AWSで本番環境を設計・構築・運用してきた人', 'expected' => ['C005'], 'note' => 'C020 は研修で学んだだけ、C001 は使う程度'],
            ['question' => 'Kubernetesでコンテナ基盤を運用した経験がある人', 'expected' => ['C005', 'C013'], 'note' => 'C013 は「コンテナオーケストレーション基盤」とだけ書く（言い換え）'],
            ['question' => 'Pythonでデータパイプラインを構築した経験がある人', 'expected' => ['C006'], 'note' => 'C014 も Python だが分析・モデル開発'],
            ['question' => '機械学習モデルを開発して本番に組み込んだ人', 'expected' => ['C014'], 'note' => ''],
            ['question' => 'IT業界での法人営業の経験がある人', 'expected' => ['C007'], 'note' => ''],
            ['question' => 'エンジニアの採用を担当していた人事', 'expected' => ['C008'], 'note' => 'C019 は人事だが採用の経験はない'],
            ['question' => '英語を使って海外の顧客と仕事をしてきた人', 'expected' => ['C018'], 'note' => ''],
            ['question' => 'Vue.jsでフロントエンドを開発してきた人', 'expected' => ['C012'], 'note' => 'C003 は React'],
        ];
    }

    /**
     * その候補者の文書に出してはいけない語（RESERVED_WORDS のうち、正解に入っていないもの）。
     *
     * @return list<string>
     */
    public static function forbiddenWordsFor(string $code): array
    {
        $words = [];
        foreach (self::RESERVED_WORDS as $reserved) {
            if (! in_array($code, $reserved['allowed'], true)) {
                array_push($words, ...$reserved['words']);
            }
        }

        return $words;
    }
}
