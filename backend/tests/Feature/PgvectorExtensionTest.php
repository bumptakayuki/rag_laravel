<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 環境構築の受け入れ基準（要件定義書 第8章 ステップ1）を確認する。
 * テスト DB でもマイグレーション後に pgvector が使えること。
 */
class PgvectorExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_postgresql_test_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        // RefreshDatabase がテーブルを作り直すので、開発 DB（rag）で動いていたら危険。必ず rag_test
        $this->assertSame('rag_test', DB::selectOne('SELECT current_database() AS db')->db);
    }

    public function test_vector_extension_is_enabled(): void
    {
        $row = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");

        $this->assertNotNull($row, 'vector 拡張が有効になっていない');
        $this->assertNotEmpty($row->extversion);
    }

    public function test_cosine_distance_operator_works(): void
    {
        // 同じ向きのベクトルはコサイン距離 0、直交するベクトルは 1
        $same = DB::selectOne("SELECT '[1,2,3]'::vector <=> '[2,4,6]'::vector AS d")->d;
        $orthogonal = DB::selectOne("SELECT '[1,0]'::vector <=> '[0,1]'::vector AS d")->d;

        $this->assertEqualsWithDelta(0.0, (float) $same, 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $orthogonal, 1e-9);
    }
}
