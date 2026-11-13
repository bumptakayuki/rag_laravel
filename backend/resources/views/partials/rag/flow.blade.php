{{--
    検索の流れ（各工程の件数と所要時間、実行SQL）。要件定義書 第4.4章
    受け取る値: $result（RagResult）、$sql（表示用の SQL）
--}}
@php
    use App\Support\RagFormat;

    $r = $result->ranking;
    $o = $result->options;
    $unit = $result->isJobSearch() ? '件' : '名';
    $t = $result->latencyMs;
    $time = fn (string ...$stages) => RagFormat::ms(array_sum(array_map(fn ($s) => (float) ($t[$s] ?? 0), $stages)));
    $sources = $result->prompt?->sources ?? [];
    $cited = $result->answer === null ? [] : array_intersect(\App\Services\Rag\Prompt::citedNumbers($result->answer), array_map(fn ($s) => $s->number, $sources));
@endphp
<section class="rounded-lg border border-[#E3E6EA] bg-white p-4" data-testid="search-flow">
    <div class="mb-3 flex items-baseline justify-between">
        <h2 class="text-base font-bold">検索の流れ</h2>
        <span class="text-xs text-[#5B6470]">合計 {{ RagFormat::ms(RagFormat::total($t)) }}</span>
    </div>
    <ol class="grid gap-3 sm:grid-cols-2 {{ $result->prompt ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
        <li class="rounded-md bg-[#F5F8FE] p-3">
            <div class="flex justify-between text-xs"><span class="font-semibold">1 ベクトル化</span><span class="text-[#5B6470]">{{ $time('embed') }}</span></div>
            @if ($result->embedding)
                <p class="mt-1 text-sm">{{ $result->mode === \App\Models\QueryLog::MODE_JOB_MATCH ? '求人票の文' : '質問' }}を {{ $result->embedding['dimensions'] }} 個の数値に</p>
                <p class="mt-1 truncate font-mono text-xs text-[#5B6470]">[{{ implode(', ', array_map(fn ($v) => number_format($v, 3), $result->embedding['head'])) }}, …]</p>
            @else
                <p class="mt-1 text-sm">質問を数値の並び（ベクトル）に</p>
            @endif
        </li>
        <li class="rounded-md bg-[#F5F8FE] p-3">
            <div class="flex justify-between text-xs"><span class="font-semibold">2 チャンク検索</span><span class="text-[#5B6470]">{{ $time('search') }}</span></div>
            <p class="mt-1 text-sm">近い順に {{ $r->retrieved }} 件取得</p>
            @if ($result->target)
                <p class="mt-1 text-xs text-[#5B6470]">{{ $result->target['documents'] }} 文書・{{ $result->target['chunks'] }} チャンクが対象</p>
            @endif
        </li>
        <li class="rounded-md bg-[#F5F8FE] p-3">
            <div class="flex justify-between text-xs"><span class="font-semibold">3 {{ $result->isJobSearch() ? '求人票' : '候補者' }}ごとに集約</span><span class="text-[#5B6470]">{{ $time('rank') }}</span></div>
            <p class="mt-1 text-sm">{{ $r->kept }} 件 → {{ $r->totalGroups }} {{ $unit }} → 上位 {{ count($r->groups) }} {{ $unit }}</p>
            <p class="mt-1 text-xs text-[#5B6470]">下限 {{ $o->minSimilarity }} 未満の {{ $r->retrieved - $r->kept }} 件を除外</p>
        </li>
        @if ($result->prompt)
            <li class="rounded-md bg-[#F5F8FE] p-3">
                <div class="flex justify-between text-xs"><span class="font-semibold">4 回答生成</span><span class="text-[#5B6470]">{{ $time('prompt', 'generate') }}</span></div>
                <p class="mt-1 text-sm">{{ count($r->groups) }} {{ $unit }}×最大 {{ $result->queryLog?->params['chunks_per_candidate'] ?? config('rag.answer.chunks_per_candidate') }} 件を Claude へ</p>
                <p class="mt-1 text-xs text-[#5B6470]">資料 {{ count($sources) }} 件、うち {{ count($cited) }} 件を引用</p>
            </li>
        @endif
    </ol>
    <details class="mt-3">
        <summary class="cursor-pointer text-xs font-semibold text-[#2457C5]">工程ごとの所要時間</summary>
        <table class="mt-2 text-xs">
            @foreach (RagFormat::orderedStages($t) as $stage => $value)
                <tr><td class="pr-4 text-[#5B6470]">{{ RagFormat::STAGES[$stage] }}</td><td class="text-right font-mono">{{ RagFormat::ms($value) }}</td></tr>
            @endforeach
            <tr class="border-t border-[#E3E6EA]"><td class="pr-4 font-semibold">合計</td><td class="text-right font-mono font-semibold">{{ RagFormat::ms(RagFormat::total($t)) }}</td></tr>
        </table>
    </details>
    <details class="mt-2">
        <summary class="cursor-pointer text-xs font-semibold text-[#2457C5]">実行したSQL（チャンク検索）</summary>
        <pre class="mt-2 overflow-x-auto rounded-md bg-[#1B1F24] p-3 text-xs leading-relaxed text-[#E3E6EA]">{{ $sql }}</pre>
        <p class="mt-1 text-xs text-[#5B6470]">:limit = {{ $o->chunkLimit }}@if ($o->documentType) 、:type = {{ $o->documentType }}@endif</p>
    </details>
</section>
