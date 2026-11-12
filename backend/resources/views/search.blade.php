@extends('layouts.app')

@section('title', '簡易RAG 検索')

@php
    use App\Models\Document;
    use App\Services\Rag\GroupRanker;
    use Illuminate\Support\Str;

    $scoreLabels = [
        GroupRanker::SCORE_MAX => '最大値',
        GroupRanker::SCORE_AVG3 => '上位3平均',
        GroupRanker::SCORE_MAX_PLUS_HITS => '最大＋件数',
    ];
    $scoreNotes = [
        GroupRanker::SCORE_MAX => 'その中のチャンクの最大類似度',
        GroupRanker::SCORE_AVG3 => '類似度の上位3チャンクの平均',
        GroupRanker::SCORE_MAX_PLUS_HITS => '最大類似度 ＋ 2件目以降のヒット1件につき '.config('rag.search.hit_bonus').'（'.config('rag.search.hit_bonus_max_hits').'件まで）',
    ];
    // 候補者カードの中は、職務経歴書 → 履歴書 の順に並べる
    $candidateTypes = [Document::TYPE_CAREER_HISTORY, Document::TYPE_RESUME];
    $ms = fn (float $v) => $v >= 1000 ? number_format($v / 1000, 1).' s' : number_format($v, $v < 10 ? 1 : 0).' ms';
    $field = 'w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]';
    $label = 'mb-1 block text-xs font-semibold text-[#3A424C]';
@endphp

@section('content')
<div class="grid gap-6 lg:grid-cols-[320px_1fr]">

    {{-- ===== 入力 ===== --}}
    <form method="GET" action="{{ route('search') }}" class="h-fit space-y-4 rounded-lg border border-[#E3E6EA] bg-white p-4">
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
                @foreach ($scoreLabels as $value => $text)
                    <label class="cursor-pointer">
                        <input type="radio" name="score" value="{{ $value }}" class="peer sr-only" @checked($form['score'] === $value)>
                        <span class="block rounded py-1.5 text-center text-[#5B6470] peer-checked:bg-white peer-checked:font-semibold peer-checked:text-[#1B1F24] peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-[#2457C5]">{{ $text }}</span>
                    </label>
                @endforeach
            </div>
            @error('score') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
        </fieldset>

        <button type="submit" class="w-full rounded-md bg-[#2457C5] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1A3F8F] focus:outline-none focus:ring-2 focus:ring-[#6F8FD8]">
            検索する
        </button>
        <p class="text-xs text-[#5B6470]">質問を OpenAI でベクトル化して検索します（回答生成はまだありません）。</p>
    </form>

    {{-- ===== 結果 ===== --}}
    <div class="min-w-0 space-y-6">
        @if ($result === null)
            <div class="rounded-lg border border-dashed border-[#C9CED5] bg-white p-8 text-center text-sm text-[#5B6470]">
                左のフォームに質問を入れて「検索する」を押すと、ここに検索の流れとランキングが出ます。
            </div>
        @else
            @php
                $r = $result['ranking'];
                $o = $result['options'];
                $unit = $result['isJob'] ? '件' : '名';
                $total = array_sum($result['timings']);
            @endphp

            {{-- 検索の流れ --}}
            <section class="rounded-lg border border-[#E3E6EA] bg-white p-4" data-testid="search-flow">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-base font-bold">検索の流れ</h2>
                    <span class="text-xs text-[#5B6470]">合計 {{ $ms($total) }}</span>
                </div>
                <ol class="grid gap-3 sm:grid-cols-3">
                    <li class="rounded-md bg-[#F5F8FE] p-3">
                        <div class="flex justify-between text-xs"><span class="font-semibold">1 ベクトル化</span><span class="text-[#5B6470]">{{ $ms($result['timings']['embed']) }}</span></div>
                        <p class="mt-1 text-sm">質問を {{ $result['dimensions'] }} 個の数値に</p>
                        <p class="mt-1 truncate font-mono text-xs text-[#5B6470]">[{{ implode(', ', array_map(fn ($v) => number_format($v, 3), $result['vectorHead'])) }}, …]</p>
                    </li>
                    <li class="rounded-md bg-[#F5F8FE] p-3">
                        <div class="flex justify-between text-xs"><span class="font-semibold">2 チャンク検索</span><span class="text-[#5B6470]">{{ $ms($result['timings']['search']) }}</span></div>
                        <p class="mt-1 text-sm">近い順に {{ $r->retrieved }} 件取得</p>
                        <p class="mt-1 text-xs text-[#5B6470]">{{ $result['target']->documents }} 文書・{{ $result['target']->chunks }} チャンクが対象</p>
                    </li>
                    <li class="rounded-md bg-[#F5F8FE] p-3">
                        <div class="flex justify-between text-xs"><span class="font-semibold">3 {{ $result['isJob'] ? '求人票' : '候補者' }}ごとに集約</span><span class="text-[#5B6470]">{{ $ms($result['timings']['rank']) }}</span></div>
                        <p class="mt-1 text-sm">{{ $r->kept }} 件 → {{ $r->totalGroups }} {{ $unit }} → 上位 {{ count($r->groups) }} {{ $unit }}</p>
                        <p class="mt-1 text-xs text-[#5B6470]">下限 {{ $o->minSimilarity }} 未満の {{ $r->retrieved - $r->kept }} 件を除外</p>
                    </li>
                </ol>
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-semibold text-[#2457C5]">実行したSQL（チャンク検索）</summary>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-[#1B1F24] p-3 text-xs leading-relaxed text-[#E3E6EA]">{{ $result['sql'] }}</pre>
                    <p class="mt-1 text-xs text-[#5B6470]">:limit = {{ $o->chunkLimit }}@if ($o->documentType) 、:type = {{ $o->documentType }}@endif</p>
                </details>
            </section>

            {{-- ランキング --}}
            <section>
                <h2 class="text-base font-bold">{{ $result['isJob'] ? '求人票' : '候補者' }}ランキング（上位 {{ count($r->groups) }} {{ $unit }} / 該当 {{ $r->totalGroups }} {{ $unit }}）</h2>
                <p class="mb-3 text-xs text-[#5B6470]">スコア = {{ $scoreNotes[$o->scoreMethod] }}。@unless ($result['isJob'])カードを押すとヒット箇所を表示@endunless</p>

                @if (count($r->groups) === 0)
                    <p class="rounded-lg border border-[#E3E6EA] bg-white p-6 text-center text-sm text-[#5B6470]">下限以上のヒットがありません。類似度の下限を下げてみてください。</p>
                @endif

                <div class="space-y-3">
                @foreach ($r->groups as $rank => $group)
                    @php $best = $group->chunks[0]; @endphp

                    @if ($result['isJob'])
                        @php
                            $doc = $result['documents'][$group->key] ?? null;
                            // ヒットした見出しごとの最大類似度
                            $sections = [];
                            foreach ($group->chunks as $chunk) {
                                $name = $chunk->section ?? '（見出しなし）';
                                $sections[$name] = max($sections[$name] ?? -1, $chunk->similarity);
                            }
                        @endphp
                        <article class="rounded-lg border border-[#E3E6EA] bg-white p-4" data-testid="job-card">
                            <div class="flex items-start gap-3">
                                <span class="text-sm font-bold text-[#5B6470]">#{{ $rank + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-bold">{{ $doc?->title ?? "文書 {$group->key}" }}</h3>
                                    <p class="text-xs text-[#5B6470]">{{ $doc?->source_path }} ・ 文書 ID {{ $group->key }} ・ ヒット {{ $group->hits }}件</p>
                                    <ul class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($sections as $name => $sim)
                                            <li class="rounded bg-[#EAF0FC] px-2 py-0.5 text-xs text-[#1A3F8F]">{{ $name }} <span class="font-mono">{{ number_format($sim, 4) }}</span></li>
                                        @endforeach
                                    </ul>
                                    <p class="mt-2 text-sm text-[#3A424C]">{{ Str::limit(str_replace("\n", ' ', $best->content), 120) }}</p>
                                </div>
                                <div class="text-right">
                                    <div class="font-mono text-lg font-bold text-[#2457C5]">{{ number_format($group->score, 4) }}</div>
                                    <div class="text-xs text-[#5B6470]">スコア</div>
                                </div>
                            </div>
                        </article>
                    @else
                        @php $candidate = $result['candidates'][$group->key] ?? null; @endphp
                        <details class="group rounded-lg border border-[#E3E6EA] bg-white open:ring-2 open:ring-[#EAF0FC]" data-testid="candidate-card">
                            <summary class="flex cursor-pointer list-none items-start gap-3 p-4 [&::-webkit-details-marker]:hidden">
                                <span class="text-sm font-bold text-[#5B6470]">#{{ $rank + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-bold">{{ $candidate?->name ?? "候補者 {$group->key}" }} <span class="ml-1 text-xs font-normal text-[#5B6470]">{{ $candidate?->code }}</span></h3>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                        <span class="text-[#5B6470]">ヒット {{ $group->hits }}件</span>
                                        @foreach ($candidateTypes as $type)
                                            @if (isset($group->hitsByType[$type]))
                                                <span class="rounded bg-[#EAF0FC] px-2 py-0.5 text-[#1A3F8F]">{{ Document::TYPE_LABELS[$type] }} {{ $group->hitsByType[$type] }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-sm text-[#3A424C]">{{ Str::limit(str_replace("\n", ' ', $best->content), 120) }}</p>
                                </div>
                                <div class="flex items-start gap-2 text-right">
                                    <div>
                                        <div class="font-mono text-lg font-bold text-[#2457C5]">{{ number_format($group->score, 4) }}</div>
                                        <div class="text-xs text-[#5B6470]">スコア</div>
                                    </div>
                                    <span aria-hidden="true" class="mt-1 text-[#5B6470] transition group-open:rotate-180">▼</span>
                                </div>
                            </summary>

                            <div class="space-y-4 border-t border-[#E3E6EA] p-4">
                                @foreach ($candidateTypes as $type)
                                    @php $hits = array_values(array_filter($group->chunks, fn ($c) => $c->documentType === $type)); @endphp
                                    @if ($o->documentType !== null && $o->documentType !== $type)
                                        @continue
                                    @endif
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold">{{ Document::TYPE_LABELS[$type] }} <span class="text-xs font-normal text-[#5B6470]">{{ count($hits) }}件ヒット</span></h4>
                                        @if (count($hits) === 0)
                                            <p class="text-xs text-[#5B6470]">この文書には下限以上のヒットがありません</p>
                                        @endif
                                        <ul class="space-y-2">
                                            @foreach ($hits as $chunk)
                                                <li class="rounded-md border border-[#E3E6EA] p-3">
                                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                                        <span><span class="font-semibold">{{ $chunk->section ?? '（見出しなし）' }}</span> <span class="text-[#5B6470]">chunk {{ $chunk->chunkIndex }}</span></span>
                                                        <span class="font-mono font-semibold text-[#2457C5]">{{ number_format($chunk->similarity, 4) }}</span>
                                                    </div>
                                                    <p class="whitespace-pre-line text-sm text-[#3A424C]">{{ $chunk->content }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
@endsection
