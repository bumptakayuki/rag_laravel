{{--
    回答・資料の対応表・送信したプロンプト全文（折りたたみ）。要件定義書 第4.4章
    受け取る値: $result（RagResult。prompt があるときだけ include する）、$candidates（id => Candidate）
--}}
@php
    use App\Models\Document;

    $queryId = $result->queryLog?->id;
@endphp
<section class="rounded-lg border border-[#2457C5] bg-white p-4" data-testid="answer">
    <div class="mb-2 flex items-baseline justify-between">
        <h2 class="text-base font-bold">回答</h2>
        <span class="text-xs text-[#5B6470]">{{ count($result->ranking->groups) }} 名・資料 {{ count($result->prompt->sources) }} 件を参照</span>
    </div>
    @if ($result->answerWithoutRag !== null)
        {{-- RAGなし比較（要件定義書 第5章）: 資料ありの回答と、資料なしで聞いた回答を並べる --}}
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <h3 class="mb-1 text-xs font-semibold text-[#1A3F8F]">RAGあり（検索した資料を根拠に回答）</h3>
                <p class="whitespace-pre-line text-sm leading-relaxed">{{ $result->answer }}</p>
            </div>
            <div class="rounded-md bg-[#F6F7F9] p-3" data-testid="answer-without-rag">
                <h3 class="mb-1 text-xs font-semibold text-[#5B6470]">RAGなし（資料を渡さずに質問だけを送った回答）</h3>
                <p class="whitespace-pre-line text-sm leading-relaxed">{{ $result->answerWithoutRag }}</p>
            </div>
        </div>
    @else
        <p class="whitespace-pre-line text-sm leading-relaxed">{{ $result->answer }}</p>
    @endif

    @if ($result->unknownCitations !== [])
        <p class="mt-3 rounded-md bg-[#FFF8E1] p-2 text-xs text-[#6B4A00]">
            注意: 回答に、送っていない資料番号 {{ implode(' ', array_map(fn ($n) => "[{$n}]", $result->unknownCitations)) }} があります（Claude の誤り）。
        </p>
    @endif

    @if ($result->prompt->sources !== [])
        <details class="mt-3">
            <summary class="cursor-pointer text-xs font-semibold text-[#2457C5]">資料番号の対応（{{ count($result->prompt->sources) }} 件）</summary>
            <ul class="mt-2 space-y-1 text-xs">
                @foreach ($result->prompt->sources as $source)
                    @php $candidate = $candidates[$source->candidateId] ?? null; @endphp
                    <li>
                        <span class="font-mono font-semibold">[{{ $source->number }}]</span>
                        <a href="{{ route('candidates.show', array_filter(['candidate' => $source->candidateId, 'query' => $queryId])) }}#chunk-{{ $source->chunk->chunkId }}" class="text-[#2457C5] hover:underline">
                            {{ $candidate?->code }} {{ $candidate?->name }}
                        </a>
                        <span class="text-[#5B6470]">{{ Document::TYPE_LABELS[$source->chunk->documentType] ?? $source->chunk->documentType }}・{{ $source->chunk->section ?? '（見出しなし）' }}</span>
                        <span class="font-mono text-[#5B6470]">{{ number_format($source->chunk->similarity, 4) }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <details class="mt-2">
        <summary class="cursor-pointer text-xs font-semibold text-[#2457C5]">送信したプロンプト全文</summary>
        <pre class="mt-2 max-h-[32rem] overflow-auto whitespace-pre-wrap rounded-md bg-[#1B1F24] p-3 text-xs leading-relaxed text-[#E3E6EA]">{{ $result->prompt->fullText() }}</pre>
    </details>
</section>
