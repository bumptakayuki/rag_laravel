@extends('layouts.app')

@section('title', "簡易RAG {$candidate->name}")

@php
    use App\Models\Document;

    $hitCount = fn (string $type) => count(array_filter($hits, fn ($h) => $h['type'] === $type));
@endphp

@section('content')
<div class="space-y-5">
    @if ($log)
        <a href="{{ $log->resultUrl() }}" class="text-sm font-semibold text-[#2457C5] hover:underline">← 検索結果に戻る</a>
    @endif

    {{-- 概要 --}}
    <section class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-[#E3E6EA] bg-white p-5">
        <div>
            <h1 class="text-xl font-bold">{{ $candidate->name }} <span class="ml-1 font-mono text-sm font-normal text-[#5B6470]">{{ $candidate->code }}</span></h1>
            @if ($log)
                <p class="text-sm text-[#3A424C]">「{{ \Illuminate\Support\Str::limit($log->question, 60) }}」でのヒット箇所を表示中</p>
            @endif
        </div>
        @if ($summary)
            <dl class="flex gap-6" data-testid="candidate-summary">
                <div><dt class="text-xs text-[#5B6470]">スコア</dt><dd class="font-mono text-xl font-semibold">{{ $summary['score'] === null ? '—' : number_format($summary['score'], 4) }}</dd></div>
                <div><dt class="text-xs text-[#5B6470]">順位</dt><dd class="font-mono text-xl font-semibold">{{ $summary['rank'] === null ? '圏外' : '#'.$summary['rank'] }}</dd></div>
                <div><dt class="text-xs text-[#5B6470]">ヒット</dt><dd class="font-mono text-xl font-semibold">{{ count($hits) }}件</dd></div>
            </dl>
        @endif
    </section>

    <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
        {{-- 文書の全文。両方の文書を出し、どちらを見せるかは下の JavaScript が決める --}}
        <section class="min-w-0 flex-1 overflow-hidden rounded-lg border border-[#E3E6EA] bg-white" aria-label="文書の全文">
            <div class="flex border-b border-[#E3E6EA]" role="tablist" aria-label="文書の切り替え">
                @foreach (Document::CANDIDATE_TYPES as $type)
                    <button type="button" role="tab" id="tab-{{ $type }}" aria-controls="panel-{{ $type }}" aria-selected="false" data-tab="{{ $type }}"
                            class="inline-flex min-h-[48px] items-center gap-2 border-b-2 border-transparent bg-[#F6F7F9] px-5 text-sm text-[#5B6470] aria-selected:border-[#2457C5] aria-selected:bg-white aria-selected:font-bold aria-selected:text-[#1B1F24]">
                        {{ Document::TYPE_LABELS[$type] }}
                        @if ($log)
                            <span class="rounded-full bg-[#EAF0FC] px-2 text-xs font-semibold text-[#1A3F8F]">ヒット {{ $hitCount($type) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            @foreach (Document::CANDIDATE_TYPES as $type)
                @php $document = $documents[$type] ?? null; @endphp
                <div id="panel-{{ $type }}" role="tabpanel" aria-labelledby="tab-{{ $type }}" data-panel="{{ $type }}" class="px-6 pb-6 pt-2" data-testid="document-body">
                    @if ($document === null)
                        <p class="py-6 text-sm text-[#5B6470]">この候補者の{{ Document::TYPE_LABELS[$type] }}は取り込まれていません。</p>
                    @else
                        <p class="py-2 font-mono text-xs text-[#5B6470]">{{ $document->source_path }}</p>
                        @foreach ($outlines[$type] as $block)
                            @if ($block['kind'] === 'heading')
                                @php $size = ['', 'text-lg', 'text-base', 'text-[15px]'][$block['level']] ?? 'text-sm'; @endphp
                                <p class="mt-4 font-bold {{ $size }}" role="heading" aria-level="{{ min($block['level'] + 1, 6) }}">{{ $block['text'] }}</p>
                            @else
                                @php $hit = $hitsByChunk[$block['id']] ?? null; @endphp
                                <div id="chunk-{{ $block['id'] }}"
                                     class="my-1.5 scroll-mt-4 rounded-lg border px-3.5 py-2.5 target:ring-2 target:ring-[#2457C5] {{ $hit ? 'border-[#F0D58A] bg-[#FFF8E1]' : 'border-transparent' }}"
                                     @if ($hit) data-testid="hit-chunk" @endif>
                                    <div class="flex flex-wrap items-center justify-end gap-1.5 text-xs">
                                        <span class="font-mono text-[#5B6470]">chunk {{ $block['chunk_index'] }}</span>
                                        @if ($hit)
                                            <span class="rounded-full bg-[#FCE7A8] px-2 font-bold text-[#6B4A00]">ヒット 類似度 {{ number_format($hit['similarity'], 4) }}</span>
                                            @foreach ($hit['cited'] as $number)
                                                <span class="rounded-full bg-[#2457C5] px-2 font-bold text-white">資料 [{{ $number }}]</span>
                                            @endforeach
                                        @endif
                                    </div>
                                    <p class="whitespace-pre-line text-[15px] leading-relaxed">{{ $block['body'] }}</p>
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>
            @endforeach
        </section>

        {{-- この検索でのヒット箇所（類似度順。先頭が一番近い） --}}
        @if ($log)
            <aside class="w-full rounded-lg border border-[#E3E6EA] bg-white p-4 lg:w-80" aria-label="この検索でのヒット箇所">
                <h2 class="mb-2 text-sm font-bold">ヒット箇所（類似度順）</h2>
                @if ($hits === [])
                    <p class="text-xs text-[#5B6470]">この検索では、下限以上のヒットがありません。</p>
                @endif
                <ol class="space-y-1.5" data-hit-list>
                    @foreach ($hits as $h)
                        <li>
                            <a href="#chunk-{{ $h['chunk_id'] }}" class="grid min-h-[44px] grid-cols-[1fr_auto] items-center gap-2 rounded-md border border-[#E3E6EA] px-2.5 py-1.5 hover:border-[#2457C5]">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-semibold text-[#1A3F8F]">{{ Document::TYPE_LABELS[$h['type']] ?? $h['type'] }}</span>
                                    <span class="block truncate text-sm font-semibold">{{ $h['section'] ?? '（見出しなし）' }}</span>
                                </span>
                                <span class="font-mono text-sm font-semibold">{{ number_format($h['similarity'], 4) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-2 text-xs text-[#5B6470]">押すと該当する文書のタブに切り替わり、その箇所へ移動します。</p>
            </aside>
        @endif
    </div>
</div>

{{--
    タブの切り替え。開くタブは
      1. URL の #chunk-{id} があれば、そのチャンクを含む文書
      2. なければ、ヒット一覧の先頭（一番近いヒット）を含む文書
      3. それもなければ最初のタブ（職務経歴書）
    JavaScript が動かないときは、両方の文書が縦に並んで表示される
--}}
<script>
(() => {
    const tabs = [...document.querySelectorAll('[data-tab]')];
    const panels = [...document.querySelectorAll('[data-panel]')];

    const show = (type) => {
        tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === type)));
        panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== type; });
    };

    // id="chunk-{id}" の要素が入っているタブ。見つからなければ null
    const tabOf = (hash) => {
        const target = hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
        return target?.closest('[data-panel]')?.dataset.panel ?? null;
    };

    // 隠れていたタブの中へ移動したときは、表示してからもう一度その位置へスクロールする
    const openHash = () => {
        const type = tabOf(location.hash);
        if (type === null) return false;
        show(type);
        document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView({ block: 'start' });
        return true;
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));
    // ヒット一覧のリンク: 先にタブを開いてから、ブラウザの通常の移動（スクロール）に任せる。
    // 今と同じ #chunk-{id} を押したときは hashchange が起きないため、ここでも切り替える
    document.querySelectorAll('[data-hit-list] a').forEach((link) => {
        link.addEventListener('click', () => {
            const type = tabOf(link.getAttribute('href'));
            if (type !== null) show(type);
        });
    });
    window.addEventListener('hashchange', openHash);

    if (!openHash()) {
        const best = document.querySelector('[data-hit-list] a')?.getAttribute('href');
        show(tabOf(best) ?? tabs[0].dataset.tab);
    }
})();
</script>
@endsection
