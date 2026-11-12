<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
            throw new RuntimeException(sprintf(
                'テストの接続先が %s ではなく %s になっている。DB を消す前に中止した（実環境変数 DB_DATABASE を確認すること）',
                self::TEST_DATABASE,
                var_export($database, true),
            ));
        }

        return parent::setUpTraits();
    }
}
