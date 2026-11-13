<?php

namespace App\Console\Commands;

use App\Services\Rag\RandomChunkSeeder;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * 索引の実験用に、ランダムなベクトルのチャンクを入れる・消す（要件定義書 第5章）。API は使わない。
 *
 *   php artisan rag:seed-random 30000     … 3万件足す（実験用の候補者 RANDOM の職務経歴書にぶら下げる）
 *   php artisan rag:seed-random --purge   … 実験用のデータをすべて消す
 */
class RagSeedRandom extends Command
{
    protected $signature = 'rag:seed-random
        {count? : 足すチャンクの数（例: 30000）}
        {--purge : 実験用のデータ（候補者 RANDOM・その文書・チャンク）をすべて消す}';

    protected $description = '索引の実験用に、ランダムなベクトルのチャンクを入れる（--purge で消す。API は使わない）';

    public function handle(RandomChunkSeeder $seeder): int
    {
        if ($this->option('purge')) {
            $deleted = $seeder->purge();
            $this->info("実験用のデータを消した（チャンク {$deleted} 件）");

            return self::SUCCESS;
        }

        $count = (string) $this->argument('count');
        if (! preg_match('/\A[1-9]\d*\z/', $count)) {
            $this->error("件数には1以上の整数を指定すること（消すときは --purge）: {$count}");

            return self::FAILURE;
        }

        $started = hrtime(true);
        try {
            $seeder->seed((int) $count, fn (int $done, int $total) => $this->line("  {$done} / {$total} 件"));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'ランダムなチャンクを %s 件入れた（%.1f 秒。実験用は合計 %s 件）',
            number_format((int) $count),
            (hrtime(true) - $started) / 1e9,
            number_format($seeder->count()),
        ));
        $this->line('消すときは: php artisan rag:seed-random --purge');

        return self::SUCCESS;
    }
}
