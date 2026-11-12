<?php

namespace App\Services\Rag\Contracts;

/**
 * 埋め込み（テキスト → ベクトル）のインターフェース（要件定義書 第6章）。
 *
 * 取り込み（チャンク）と質問の両方で同じ実装を使う。同じモデルでベクトル化しないと、
 * 「向きの近さ」を比べる意味がなくなるため。
 * 実装は AppServiceProvider でバインドする（OpenAI 以外へ差し替えられるようにする）。
 */
interface Embedder
{
    /**
     * @param  list<string>  $texts  ベクトル化するテキスト（件数の上限はない。必要なら実装側で分けて送る）
     * @return list<list<float>> $texts と同じ順番・同じ件数のベクトル
     */
    public function embed(array $texts): array;
}
