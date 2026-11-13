<?php

namespace App\Services\Rag;

use App\Models\Candidate;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 索引の実験用に、ランダムなベクトルのチャンクを大量に入れる・消す（rag:seed-random。要件定義書 第5章）。
 *
 * 外部 API は使わない。ベクトルは PostgreSQL の random() で DB の中で作る
 * （1536 次元 × 数万件を PHP で作って送ると、SQL の文字列だけで数百 MB になるため）。
 *
 * 実験後にまとめて消せるよう、次の2つの目印を付ける:
 * - 実験用の架空の候補者（コード RANDOM_CODE）1名の職務経歴書に、すべてのチャンクをぶら下げる。
 *   消すときはこの候補者を消せば、documents・chunks も cascade で消える
 * - chunks.metadata に {"random": true} を入れる（SQL で見分けられるように）
 *
 * ランダムなベクトルは実際の文章のベクトルとほぼ直交する（類似度は 0 付近）ので、
 * 検索の上位には出てこない。検索の「対象の件数」だけを増やし、索引の有無で速さと実行計画を比べるためのもの。
 */
class RandomChunkSeeder
{
    /** 実験用の候補者コード（サンプルの C001〜C020 とぶつからない名前） */
    public const RANDOM_CODE = 'RANDOM';

    /** 1回で入れられる上限（うっかり桁を間違えて DB をいっぱいにしないように） */
    public const MAX_COUNT = 200_000;

    /** 1回の INSERT で入れる件数 */
    private const BATCH = 5_000;

    private readonly int $dimensions;

    /** @param  int|null  $dimensions  ベクトルの次元数。null なら設定（chunks.embedding と同じ次元数） */
    public function __construct(?int $dimensions = null)
    {
        $this->dimensions = $dimensions ?? (int) config('rag.embedding.dimensions');
    }

    /**
     * ランダムなベクトルのチャンクを $count 件足す。何度か実行すると足し続ける。
     *
     * @param  (callable(int $inserted, int $count): void)|null  $progress  バッチごとに呼ぶ（進み具合の表示用）
     * @return int 入れた件数
     */
    public function seed(int $count, ?callable $progress = null): int
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('件数は1〜%d にすること: %d', self::MAX_COUNT, $count));
        }

        $document = $this->document();
        // 前回の続きの番号から入れる（document_id と chunk_index の組は unique）
        $next = (int) Chunk::where('document_id', $document->id)->max('chunk_index') + ($document->chunks()->exists() ? 1 : 0);

        $inserted = 0;
        while ($inserted < $count) {
            $size = min(self::BATCH, $count - $inserted);
            $from = $next + $inserted;

            // 1行ごとに random() - 0.5 を次元数だけ並べた配列を作り、vector に変換する。
            // LATERAL の WHERE で外側の s.i を参照しているのは、行ごとに別のベクトルを作らせるため
            // （参照しないと、PostgreSQL がサブクエリを1回だけ実行して全行が同じベクトルになる）。
            // コサイン距離は長さに関係しないので、単位ベクトルにそろえる必要はない
            DB::insert(<<<'SQL'
                INSERT INTO chunks (document_id, chunk_index, content, token_estimate, metadata, embedding, created_at, updated_at)
                SELECT ?, s.i, '（ランダムベクトル #' || s.i || '）', 0, ?::jsonb, v.vec, now(), now()
                FROM generate_series(?::int, ?::int) AS s(i)
                CROSS JOIN LATERAL (
                    SELECT array_agg(random() - 0.5)::vector AS vec FROM generate_series(1, ?::int) WHERE s.i IS NOT NULL
                ) AS v
                SQL, [
                $document->id,
                json_encode(['random' => true, 'type' => Document::TYPE_CAREER_HISTORY, 'section' => 'ランダム'], JSON_UNESCAPED_UNICODE),
                $from,
                $from + $size - 1,
                $this->dimensions,
            ]);

            $inserted += $size;
            if ($progress !== null) {
                $progress($inserted, $count);
            }
        }

        // 件数が大きく変わったので統計を取り直す（planner が Seq Scan と Index Scan を正しく比べられるように）
        DB::statement('ANALYZE chunks');

        return $inserted;
    }

    /**
     * 実験用のデータ（候補者・文書・チャンク）をすべて消す。
     *
     * @return int 消したチャンクの数
     */
    public function purge(): int
    {
        $deleted = $this->count();
        // candidates → documents → chunks は cascade で消える
        Candidate::where('code', self::RANDOM_CODE)->delete();
        DB::statement('ANALYZE chunks');

        return $deleted;
    }

    /** いま入っているランダムなチャンクの数 */
    public function count(): int
    {
        return Chunk::whereRelation('document.candidate', 'code', self::RANDOM_CODE)->count();
    }

    /** 実験用の候補者と職務経歴書（なければ作る） */
    private function document(): Document
    {
        $candidate = Candidate::firstOrCreate(['code' => self::RANDOM_CODE], ['name' => '（実験用）ランダムベクトル']);

        return Document::firstOrCreate(
            ['candidate_id' => $candidate->id, 'type' => Document::TYPE_CAREER_HISTORY],
            [
                'title' => '（実験用）ランダムベクトル',
                'source_path' => 'rag:seed-random',
                'content' => "# （実験用）ランダムベクトル\n\nrag:seed-random が入れた、索引の実験用のチャンクです。rag:seed-random --purge で消せます。",
                // content_hash は unique。サンプルの文書とぶつからない固定の値にする
                'content_hash' => hash('sha256', 'rag:seed-random'),
            ],
        );
    }
}
