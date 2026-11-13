<?php

namespace App\Services\Rag;

use App\Models\Document;
use App\Services\Rag\Contracts\Embedder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 検索の1段目: 質問に近いチャンクを取る（要件定義書 第4.2章）。
 *
 * 質問を取り込み時と同じ埋め込みモデルでベクトル化し、pgvector のコサイン距離演算子 <=> の近い順に
 * 上位 $limit 件を生SQLで取得する。候補者ごとの集約は2段目（GroupRanker / CandidateRanker）の仕事。
 */
class Retriever
{
    /** 候補者の文書（履歴書・職務経歴書）を探す */
    public const SCOPE_CANDIDATE = 'candidate';

    /** 求人票を探す */
    public const SCOPE_JOB = 'job';

    public function __construct(private readonly Embedder $embedder) {}

    /**
     * テキストをベクトル化して検索する。
     *
     * @param  string|null  $documentType  候補者の文書だけを種別で絞る（rirekisho / shokumu）。null はすべて
     * @return list<RetrievedChunk> 類似度の高い順
     */
    public function retrieve(string $text, string $scope, int $limit, ?string $documentType = null): array
    {
        // 引数の誤りは API を呼ぶ前に止める（埋め込みは有料の外部 API のため）
        $this->conditions($scope, $limit, $documentType);
        if (trim($text) === '') {
            throw new InvalidArgumentException('検索するテキストが空');
        }
        $vector = $this->embedder->embed([$text])[0];

        return $this->retrieveByVector($vector, $scope, $limit, $documentType);
    }

    /**
     * ベクトルで検索する（埋め込み済みのベクトルを使い回すとき・テスト用）。
     *
     * @param  list<float>  $vector
     * @return list<RetrievedChunk> 類似度の高い順
     */
    public function retrieveByVector(array $vector, string $scope, int $limit, ?string $documentType = null): array
    {
        [$where, $bindings] = $this->conditions($scope, $limit, $documentType);

        // pgvector に渡すベクトルは '[0.1,0.2,...]' という文字列にして ::vector で型を付ける
        $literal = '['.implode(',', array_map(fn ($v) => (string) (float) $v, $vector)).']';

        $rows = DB::select($this->buildSql($where), [$literal, ...$bindings, $literal, $limit]);

        return array_map(fn ($row) => new RetrievedChunk(
            chunkId: (int) $row->id,
            documentId: (int) $row->document_id,
            candidateId: $row->candidate_id === null ? null : (int) $row->candidate_id,
            documentType: $row->type,
            chunkIndex: (int) $row->chunk_index,
            section: $row->section,
            content: $row->content,
            similarity: (float) $row->similarity,
        ), $rows);
    }

    /**
     * 実行する SQL を画面に見せる形で返す（? は :q / :type / :limit に置き換える）。
     */
    public function sqlForDisplay(string $scope, int $limit, ?string $documentType = null): string
    {
        [$where] = $this->conditions($scope, $limit, $documentType);
        $sql = $this->buildSql($where);

        // バインドする値（質問ベクトル・文書種別・件数）を名前つきの表記にする。実行には使わない
        $sql = preg_replace('/\?::vector/', ':q', $sql);
        $sql = preg_replace('/d\.type = \?/', 'd.type = :type', $sql);

        return preg_replace('/LIMIT \?/', 'LIMIT :limit', $sql);
    }

    /**
     * 検索条件だけを確かめる（誤りなら例外）。埋め込みを自分で呼ぶ側（RagPipeline）が、API を呼ぶ前に使う
     */
    public function validate(string $scope, int $limit, ?string $documentType = null): void
    {
        $this->conditions($scope, $limit, $documentType);
    }

    /**
     * 検索の対象になる文書数とチャンク数（埋め込み済みのものだけ）。画面の「検索の流れ」に出す
     *
     * @return array{documents: int, chunks: int}
     */
    public function countTargets(string $scope, ?string $documentType = null): array
    {
        // 条件は検索と同じものを使う（LIMIT は関係ないので 1 を渡す）
        [$where, $bindings] = $this->conditions($scope, 1, $documentType);
        $row = DB::selectOne(
            "SELECT count(DISTINCT d.id) AS documents, count(*) AS chunks FROM chunks c JOIN documents d ON d.id = c.document_id WHERE c.embedding IS NOT NULL AND {$where}",
            $bindings,
        );

        return ['documents' => (int) $row->documents, 'chunks' => (int) $row->chunks];
    }

    /**
     * 類似度 = 1 − コサイン距離。並べ替えは距離そのもの（ORDER BY embedding <=> q）だけで行う。
     * pgvector の HNSW 索引が使えるのはこの形（他の並べ替えキーを足すと使われなくなるおそれがある）。
     * 実際に索引が使われるかは RS-007 の rag:explain で確かめる
     */
    private function buildSql(string $where): string
    {
        return <<<SQL
            SELECT c.id, c.document_id, d.candidate_id, d.type, c.chunk_index,
                   c.metadata->>'section' AS section, c.content,
                   1 - (c.embedding <=> ?::vector) AS similarity
            FROM chunks c
            JOIN documents d ON d.id = c.document_id
            WHERE c.embedding IS NOT NULL AND {$where}
            ORDER BY c.embedding <=> ?::vector
            LIMIT ?
            SQL;
    }

    /**
     * 引数を確かめ、WHERE 句とそのバインド値を返す。
     *
     * @return array{0: string, 1: list<string>}
     */
    private function conditions(string $scope, int $limit, ?string $documentType): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("取得件数は1以上にすること: {$limit}");
        }

        // 種別の条件は1段目の WHERE で絞る（要件定義書 第4.2章）。固定の文字列は定数から組み、利用者の指定はバインドする
        $where = match ($scope) {
            self::SCOPE_CANDIDATE => 'd.candidate_id IS NOT NULL',
            self::SCOPE_JOB => "d.type = '".Document::TYPE_JOB."'",
            default => throw new InvalidArgumentException("検索対象が不正: {$scope}"),
        };

        $bindings = [];
        if ($documentType !== null) {
            if ($scope !== self::SCOPE_CANDIDATE || ! in_array($documentType, [Document::TYPE_RESUME, Document::TYPE_CAREER_HISTORY], true)) {
                throw new InvalidArgumentException("文書種別で絞れるのは候補者の履歴書・職務経歴書だけ: {$documentType}");
            }
            $where .= ' AND d.type = ?';
            $bindings[] = $documentType;
        }

        return [$where, $bindings];
    }
}
