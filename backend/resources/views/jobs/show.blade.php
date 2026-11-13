@extends('layouts.app')

@section('title', "簡易RAG {$jobTitle}")

@php
    use App\Http\Controllers\JobController;

    // 見出しごとにまとめる: [見出し（最初の見出しより前は null）, 見出しの深さ, その下のチャンク]
    $sections = [];
    foreach ($outline as $block) {
        if ($block['kind'] === 'heading') {
            $sections[] = ['title' => $block['text'], 'level' => $block['level'], 'chunks' => []];
        } else {
            if ($sections === []) {
                $sections[] = ['title' => null, 'level' => 0, 'chunks' => []];
            }
            $sections[array_key_last($sections)]['chunks'][] = $block;
        }
    }
@endphp

@section('content')
<div class="space-y-5">
    <a href="{{ route('jobs.index') }}" class="text-sm font-semibold text-[#2457C5] hover:underline">← 求人票一覧に戻る</a>

    <div class="flex flex-wrap items-baseline gap-3">
        <h1 class="text-xl font-bold">{{ $jobTitle }}</h1>
        <span class="font-mono text-xs text-[#5B6470]">{{ $job->source_path }} ・ {{ $job->chunks->count() }}チャンク</span>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
        {{-- 全文（見出しごと。各見出しがどのチャンクに分かれたか） --}}
        <section class="min-w-0 rounded-lg border border-[#E3E6EA] bg-white px-5 pb-5 pt-3" aria-label="求人票の全文">
            <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                <h2 class="text-base font-bold">求人票の全文</h2>
                <span class="inline-flex items-center gap-1.5 text-xs text-[#3A424C]"><span class="h-3 w-3 rounded-sm border border-[#9DB5EA] bg-[#EAF0FC]"></span>候補者検索に使う部分</span>
            </div>
            @foreach ($sections as $section)
                @php
                    // 全文（usedSections = null）のときは本文のある節すべて、それ以外は選んだ見出しの節
                    $used = $usedSections === null ? $section['chunks'] !== [] : in_array($section['title'], $usedSections, true);
                @endphp
                <div class="my-1.5 rounded-lg border px-3.5 py-2.5 {{ $used ? 'border-[#9DB5EA] bg-[#EAF0FC]' : 'border-transparent' }}" data-testid="job-section">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @if ($section['title'] !== null)
                            <p class="font-bold {{ $section['level'] <= 1 ? 'text-lg' : 'text-[15px]' }}" role="heading" aria-level="{{ min($section['level'] + 2, 6) }}">{{ $section['title'] }}</p>
                        @endif
                        <span class="flex flex-wrap items-center gap-1.5 text-xs">
                            @if ($section['chunks'] !== [])
                                <span class="font-mono text-[#5B6470]">chunk {{ implode(', ', array_column($section['chunks'], 'chunk_index')) }}</span>
                            @endif
                            @if ($used && $usedSections !== null)
                                <span class="rounded-full bg-[#D6E2FA] px-2 font-bold text-[#1A3F8F]">検索に使用</span>
                            @endif
                        </span>
                    </div>
                    @foreach ($section['chunks'] as $chunk)
                        <p id="chunk-{{ $chunk['id'] }}" class="mt-1.5 whitespace-pre-line text-[15px] leading-relaxed">{{ $chunk['body'] }}</p>
                    @endforeach
                </div>
            @endforeach
        </section>

        {{-- この求人に合う候補者を探す --}}
        <div class="min-w-0 space-y-5">
            <form method="POST" action="{{ route('jobs.match', $job) }}" data-loading="候補者を探しています…" class="space-y-4 rounded-lg border border-[#E3E6EA] bg-white p-5">
                @csrf
                <h2 class="text-base font-bold">この求人に合う候補者を探す</h2>
                <fieldset>
                    <legend class="mb-1.5 text-xs font-semibold text-[#3A424C]">検索に使う部分</legend>
                    <div class="grid grid-cols-3 gap-1 rounded-md bg-[#EEF0F3] p-1 text-sm">
                        @foreach (JobController::SOURCE_LABELS as $value => $text)
                            <label class="cursor-pointer">
                                <input type="radio" name="source" value="{{ $value }}" class="peer sr-only" @checked($source === $value)>
                                <span class="block rounded py-1.5 text-center text-[#5B6470] peer-checked:bg-white peer-checked:font-semibold peer-checked:text-[#1B1F24] peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-[#2457C5]">{{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('source') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </fieldset>
                <div>
                    <p class="mb-1 text-xs font-semibold text-[#5B6470]">
                        質問の代わりに使う文（{{ JobController::SOURCE_LABELS[$source] }}@if ($sourceText !== null)・{{ mb_strlen($sourceText) }}字 → 1本のベクトル@endif）
                    </p>
                    @if ($sourceError !== null)
                        <p class="rounded-md bg-red-50 p-2 text-xs text-red-800">{{ $sourceError }}</p>
                    @else
                        <pre class="max-h-32 overflow-auto whitespace-pre-wrap rounded-md bg-[#F6F7F9] p-2.5 font-sans text-[13px] text-[#3A424C]" data-testid="source-text">{{ $sourceText }}</pre>
                    @endif
                    <p class="mt-1 text-xs text-[#5B6470]">左の全文で色のついた部分です（表示は今選んでいる部分。切り替えて実行すると変わります）。</p>
                </div>
                <button type="submit" class="w-full rounded-md bg-[#2457C5] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1A3F8F] focus:outline-none focus:ring-2 focus:ring-[#6F8FD8] disabled:cursor-wait disabled:opacity-70">候補者を探す</button>
                <p class="text-xs text-[#5B6470]">件数・類似度の下限・スコアの決め方は設定の初期値（{{ config('rag.search.top_k') }}名 / 下限 {{ config('rag.search.min_similarity') }} / {{ \App\Support\RagFormat::SCORE_LABELS[config('rag.search.candidate_score')] ?? config('rag.search.candidate_score') }}）を使います。</p>
            </form>

            @if ($result)
                @include('partials.rag.record-error')
                @if ($result->queryLog)
                    <p class="text-xs text-[#5B6470]">履歴 #{{ $result->queryLog->id }}（{{ $result->queryLog->created_at?->format('Y-m-d H:i') }}・{{ JobController::SOURCE_LABELS[$result->queryLog->params['source'] ?? ''] ?? '' }}）の結果</p>
                @endif
                @include('partials.rag.flow')
                @include('partials.rag.ranking')
            @endif
        </div>
    </div>
</div>
@endsection
