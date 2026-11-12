<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** テストが使ってよい DB 名。phpunit.xml の DB_DATABASE と一致させる */
    private const TEST_DATABASE = 'rag_test';

    /**
     * RefreshDatabase などのトレイトが DB を作り直す「前」に、接続先がテスト DB かを確かめる。
     *
     * 実環境変数（compose.yaml の environment など）に DB_DATABASE があると、
     * Laravel は $_SERVER の値を優先するため phpunit.xml の指定が効かず、開発 DB（rag）が消える。
     * 消えた後で気づいても遅いので、ここで止める。
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($database !== self::TEST_DATABASE) {
            $this->abortBeforeTouchingDatabase(sprintf(
                '設定上の DB 名が %s になっている（実環境変数 DB_DATABASE を確認すること）',
                var_export($database, true),
            ));
        }

        // DB_URL があると database より優先され、上の確認をすり抜けて別の DB につながる
        $url = config("database.connections.{$connection}.url");
        if (! empty($url)) {
            $this->abortBeforeTouchingDatabase('DB_URL が設定されている（実環境変数 DB_URL を確認すること）');
        }

        // 設定ではなく「実際に接続した DB」の名前も確かめる（DB_HOST などで別サーバーを指していても気づける）
        $actual = DB::connection($connection)->selectOne('SELECT current_database() AS db')->db;
        if ($actual !== self::TEST_DATABASE) {
            $this->abortBeforeTouchingDatabase(sprintf('実際の接続先が %s になっている', var_export($actual, true)));
        }

        $traits = parent::setUpTraits();

        // テストはネットワークを使わない。Http::fake() していない宛先への送信は例外にする
        Http::preventStrayRequests();

        return $traits;
    }

    private function abortBeforeTouchingDatabase(string $reason): never
    {
        throw new RuntimeException(sprintf(
            'テストの接続先が %s ではない: %s。DB を消す前に中止した',
            self::TEST_DATABASE,
            $reason,
        ));
    }
}
