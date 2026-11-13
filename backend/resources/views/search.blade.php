@extends('layouts.app')

@section('title', '簡易RAG 検索')

@php
    use App\Models\Document;
    use App\Support\RagFormat;

    $field = 'w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]';
    $label = 'mb-1 block text-xs font-semibold text-[#3A424C]';
@endphp

@section('content')
<div class="grid gap-6 lg:grid-cols-[320px_1fr]">

    {{-- ===== 入力 ===== --}}
    {{-- 実行は POST（回答生成は有料の API。記録もする）。結果は /?query={id} にリダイレクトして表示する --}}
    <form method="POST" action="{{ route('search.run') }}" data-loading="検索・回答を作成しています…" class="h-fit space-y-4 rounded-lg border border-[#E3E6EA] bg-white p-4">
        @csrf
        <h1 class="text-base font-bold">検索する</h1>

        <fieldset>
            <legend class="{{ $label }}">検索対象</legend>
            <div class="grid grid-cols-2 gap-1 rounded-md bg-[#EEF0F3] p-1 text-sm">
                @foreach (['candidate' => '候補者', 'job' => '求人票'] as $value => $text)
                    <label class="cursor-pointer">
                        <input type="radio" name="scope" value="{{ $value }}" class="peer sr-only" @checked($form['scope'] === $value)>
                        <span class="block rounded py-1.5 text-center text-[#5B6470] peer-checked:bg-white peer-checked:font-semibold peer-checked:text-[#1B1F24] peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-[#2457C5]">{{ $text }}</span>
                    </label>
                @endforeach
            </div>
            @error('scope') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label for="q" class="{{ $label }}">質問</label>
            <textarea id="q" name="q" rows="3" class="{{ $field }}" placeholder="例: PHPの経験がある人">{{ $form['q'] }}</textarea>
            @error('q') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="top_k" class="{{ $label }}">表示する件数</label>
                <input id="top_k" name="top_k" type="number" min="1" max="50" value="{{ $form['top_k'] }}" class="{{ $field }}">
                @error('top_k') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="min_similarity" class="{{ $label }}">類似度の下限</label>
                <input id="min_similarity" name="min_similarity" type="number" min="-1" max="1" step="0.01" value="{{ $form['min_similarity'] }}" class="{{ $field }}">
                @error('min_similarity') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="doc_type" class="{{ $label }}">検索する文書（候補者のみ）</label>
            <select id="doc_type" name="doc_type" class="{{ $field }}">
                <option value="" @selected(! $form['doc_type'])>履歴書と職務経歴書</option>
                <option value="{{ Document::TYPE_CAREER_HISTORY }}" @selected($form['doc_type'] === Document::TYPE_CAREER_HISTORY)>職務経歴書のみ</option>
                <option value="{{ Document::TYPE_RESUME }}" @selected($form['doc_type'] === Document::TYPE_RESUME)>履歴書のみ</option>
            </select>
            @error('doc_type') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="{{ $label }}">スコアの決め方</legend>
            <div class="grid grid-cols-3 gap-1 rounded-md bg-[#EEF0F3] p-1 text-xs">
                @foreach (RagFormat::SCORE_LABELS as $value => $text)
                    <label class="cursor-pointer">
                        <input type="radio" name="score" value="{{ $value }}" class="peer sr-only" @checked($form['score'] === $value)>
                        <span class="block rounded py-1.5 text-center text-[#5B6470] peer-checked:bg-white peer-checked:font-semibold peer-checked:text-[#1B1F24] peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-[#2457C5]">{{ $text }}</span>
                    </label>
                @endforeach
            </div>
            @error('score') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="compare_without_rag" value="1" class="mt-0.5" @checked($form['compare_without_rag'] ?? false)>
            <span>
                RAGなしの回答と比べる（候補者のみ）
                <span class="block text-xs text-[#5B6470]">同じ質問を資料なしで Claude にも聞き、回答を並べて表示します（API を1回多く呼びます）</span>
            </span>
        </label>
        @error('compare_without_rag') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror

        <div class="space-y-2">
            <button type="submit" name="action" value="ask" class="w-full rounded-md bg-[#2457C5] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1A3F8F] focus:outline-none focus:ring-2 focus:ring-[#6F8FD8] disabled:cursor-wait disabled:opacity-70">
                検索して回答する
            </button>
            <button type="submit" name="action" value="search" data-loading="検索しています…" class="w-full rounded-md border border-[#2457C5] bg-white px-4 py-2 text-sm font-semibold text-[#2457C5] hover:bg-[#EAF0FC] focus:outline-none focus:ring-2 focus:ring-[#6F8FD8] disabled:cursor-wait disabled:opacity-70">
                検索だけ実行（Claude を呼ばない）
            </button>
        </div>
        @error('action') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        <p class="text-xs text-[#5B6470]">質問を OpenAI でベクトル化して検索し、候補者のときはヒットした資料だけを根拠に Claude が回答します。求人票のときは検索だけです。「検索だけ実行」は Claude を呼ばないので、API 費用を抑えて検索の精度を試せます。</p>
    </form>

    {{-- ===== 結果 ===== --}}
    <div class="min-w-0 space-y-6">
        @if ($result === null)
            <div class="rounded-lg border border-dashed border-[#C9CED5] bg-white p-8 text-center text-sm text-[#5B6470]">
                左のフォームに質問を入れて「検索する」を押すと、ここに検索の流れとランキングが出ます。
            </div>
        @else
            @include('partials.rag.record-error')

            <section class="rounded-lg border border-[#E3E6EA] bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-xs font-semibold text-[#5B6470]">
                        {{ \App\Models\QueryLog::MODE_LABELS[$result->mode] ?? $result->mode }}
                        @if ($result->queryLog) ・ 履歴 #{{ $result->queryLog->id }}（{{ $result->queryLog->created_at?->format('Y-m-d H:i') }}）@endif
                    </h2>
                    @if ($result->queryLog?->sourceDocument)
                        <a href="{{ $result->queryLog->resultUrl() }}" class="text-xs font-semibold text-[#2457C5] hover:underline">求人票詳細で見る</a>
                    @endif
                </div>
                <p class="mt-1 whitespace-pre-line text-sm" data-testid="question">{{ $result->searchText }}</p>
            </section>

            @include('partials.rag.flow')

            @if ($result->prompt)
                @include('partials.rag.answer')
            @elseif ($result->searchOnly)
                <p class="rounded-lg border border-dashed border-[#C9CED5] bg-white p-3 text-xs text-[#5B6470]" data-testid="search-only">
                    検索だけ実行しました（Claude は呼んでいません）。回答を見るには「検索して回答する」を押してください。
                </p>
            @endif

            @include('partials.rag.ranking')
        @endif
    </div>
</div>
@endsection
