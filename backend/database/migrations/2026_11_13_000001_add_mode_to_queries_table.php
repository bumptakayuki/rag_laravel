<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 質問履歴に「何の検索だったか」を足す（要件定義書 第3章。RS-005）。
 *
 * - mode: question（質問から候補者検索）/ job_search（求人票の検索）/ job_match（求人票から候補者検索）
 * - source_document_id: job_match のときの元の求人票。求人票を取り込み直して消えても履歴は残したいので、
 *   消えたら NULL にする（cascade で履歴ごと消さない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            // 既存の行（RS-005 より前に記録したもの）は質問として扱う
            $table->string('mode')->default('question')->after('id');
            $table->foreignId('source_document_id')->nullable()->after('mode')
                ->constrained('documents')->nullOnDelete();
        });

        DB::statement("ALTER TABLE queries ADD CONSTRAINT queries_mode_check CHECK (mode IN ('question', 'job_search', 'job_match'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE queries DROP CONSTRAINT IF EXISTS queries_mode_check');

        Schema::table('queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropColumn('mode');
        });
    }
};
