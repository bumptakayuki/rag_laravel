<?php

namespace App\Console\Commands;

use App\Services\Rag\Samples\SampleGenerator;
use Illuminate\Console\Command;
use Throwable;

/**
 * ダミーデータの生成（要件定義書 第4.1章・第3章「ダミーデータ」）。
 * 生成したファイルはリポジトリにコミットし、毎回同じデータで実験する。
 */
class RagSeedSamples extends Command
{
    protected $signature = 'rag:seed-samples
        {--force : 既にあるファイルも作り直す（Claude API の費用がかかる）}';

    protected $description = '架空の候補者20名の履歴書・職務経歴書と求人票10件を storage/app/samples/ に生成する（Claude API を使う）';

    public function handle(SampleGenerator $generator): int
    {
        try {
            $result = $generator->generate(
                dir: storage_path('app/samples'),
                evalPath: storage_path('app/eval.json'),
                force: (bool) $this->option('force'),
                log: fn (string $message) => $this->line($message),
            );
        } catch (Throwable $e) {
            // それまでに書き出したファイルは残る。もう一度実行すると、無いものだけ生成する
            $this->error('生成に失敗した: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('生成: %d 件 / 既存のためスキップ: %d 件', count($result['generated']), count($result['skipped'])));
        $this->line('評価データ: storage/app/eval.json');

        return self::SUCCESS;
    }
}
