{{--
    ランキング（候補者: 1人1カード / 求人票: 1求人1カード）。要件定義書 第4.4章
    受け取る値: $result（RagResult）、$candidates（id => Candidate）、$documents（id => Document）
    候補者カードは <details> で展開し、ヒットしたチャンクを文書別に出す。各チャンクから候補者詳細の該当箇所へ移動できる
--}}
@php
    use App\Http\Controllers\JobController;
    use App\Models\Document;
    use App\Support\RagFormat;
    use Illuminate\Support\Str;

    $r = $result->ranking;
    $o = $result->options;
    $isJob = $result->isJobSearch();
    $unit = $isJob ? '件' : '名';
    $queryId = $result->queryLog?->id;
    // 候補者詳細へのリンク（記録がある実行なら、その検索のヒット箇所をハイライトする）
    // タブは候補者詳細の JavaScript が #chunk-{id} から決めるので、URL には付けない
    $candidateUrl = fn (int $id) => route('candidates.show', array_filter(['candidate' => $id, 'query' => $queryId]));
@endphp
<section>
    <h2 class="text-base font-bold">{{ $isJob ? '求人票' : '候補者' }}ランキング（上位 {{ count($r->groups) }} {{ $unit }} / 該当 {{ $r->totalGroups }} {{ $unit }}）</h2>
    <p class="mb-3 text-xs text-[#5B6470]">スコア = {{ RagFormat::scoreNote($o->scoreMethod) }}。@unless ($isJob)カードを押すとヒット箇所を表示@endunless</p>

    @if ($result->missingChunks > 0)
        <p class="mb-3 rounded-md bg-[#FFF8E1] p-2 text-xs text-[#6B4A00]">記録したチャンクのうち {{ $result->missingChunks }} 件は、取り込み直しで削除されているため表示していません。</p>
    @endif

    @if (count($r->groups) === 0)
        <p class="rounded-lg border border-[#E3E6EA] bg-white p-6 text-center text-sm text-[#5B6470]">下限以上のヒットがありません。類似度の下限を下げてみてください。</p>
    @endif

    <div class="space-y-3">
    @foreach ($r->groups as $rank => $group)
        @php $best = $group->chunks[0] ?? null; @endphp

        @if ($isJob)
            @php
                $doc = $documents[$group->key] ?? null;
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
                        <h3 class="font-bold">{{ $doc ? JobController::jobTitle($doc) : "文書 {$group->key}（削除済み）" }}</h3>
                        <p class="text-xs text-[#5B6470]">{{ $doc?->source_path }} ・ 文書 ID {{ $group->key }} ・ ヒット {{ $group->hits }}件</p>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($sections as $name => $sim)
                                <li class="rounded bg-[#EAF0FC] px-2 py-0.5 text-xs text-[#1A3F8F]">{{ $name }} <span class="font-mono">{{ number_format($sim, 4) }}</span></li>
                            @endforeach
                        </ul>
                        @if ($best)
                            <p class="mt-2 text-sm text-[#3A424C]">{{ Str::limit(str_replace("\n", ' ', $best->content), 120) }}</p>
                        @endif
                        @if ($doc)
                            <a href="{{ route('jobs.show', $doc) }}" class="mt-2 inline-block text-sm font-semibold text-[#2457C5] hover:underline">求人票を開く（この求人に合う候補者も探せます）</a>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="font-mono text-lg font-bold text-[#2457C5]">{{ number_format($group->score, 4) }}</div>
                        <div class="text-xs text-[#5B6470]">スコア</div>
                    </div>
                </div>
            </article>
        @else
            @php $candidate = $candidates[$group->key] ?? null; @endphp
            <details class="group rounded-lg border border-[#E3E6EA] bg-white open:ring-2 open:ring-[#EAF0FC]" data-testid="candidate-card">
                <summary class="flex cursor-pointer list-none items-start gap-3 p-4 [&::-webkit-details-marker]:hidden">
                    <span class="text-sm font-bold text-[#5B6470]">#{{ $rank + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-bold">{{ $candidate?->name ?? "候補者 {$group->key}" }} <span class="ml-1 text-xs font-normal text-[#5B6470]">{{ $candidate?->code }}</span></h3>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="text-[#5B6470]">ヒット {{ $group->hits }}件</span>
                            @foreach (Document::CANDIDATE_TYPES as $type)
                                @if (isset($group->hitsByType[$type]))
                                    <span class="rounded bg-[#EAF0FC] px-2 py-0.5 text-[#1A3F8F]">{{ Document::TYPE_LABELS[$type] }} {{ $group->hitsByType[$type] }}</span>
                                @endif
                            @endforeach
                        </div>
                        @if ($best)
                            <p class="mt-2 text-sm text-[#3A424C]">{{ Str::limit(str_replace("\n", ' ', $best->content), 120) }}</p>
                        @endif
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
                    @foreach (Document::CANDIDATE_TYPES as $type)
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
                                        <a href="{{ $candidateUrl($group->key) }}#chunk-{{ $chunk->chunkId }}" class="mt-1 inline-block text-xs font-semibold text-[#2457C5] hover:underline" data-testid="chunk-link">全文の該当箇所を見る</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                    <a href="{{ $candidateUrl($group->key) }}" class="inline-block text-sm font-semibold text-[#2457C5] hover:underline">候補者詳細を開く</a>
                </div>
            </details>
        @endif
    @endforeach
    </div>
</section>
