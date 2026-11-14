@extends('layouts.app')

@section('title', '簡易RAG 候補者一覧')

@php use App\Models\Document; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold">候補者一覧</h1>
            <p class="text-sm text-[#5B6470]">取り込み済み {{ $candidateCount }}名・合計 {{ $chunkTotal }}チャンク</p>
        </div>
    </div>

    {{-- 絞り込み（GET。JS は使わない）。すべて文字列の一致で、指定したものを AND で組み合わせる --}}
    <form method="GET" action="{{ route('candidates.index') }}" class="rounded-lg border border-[#E3E6EA] bg-white p-4" data-testid="candidate-filter">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="q" class="mb-1 block text-xs font-semibold text-[#3A424C]">コード・名前</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="例: C001" class="w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]">
            </div>
            <div>
                <label for="job" class="mb-1 block text-xs font-semibold text-[#3A424C]">希望職種</label>
                <select id="job" name="job" class="w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]">
                    <option value="">すべて</option>
                    @foreach ($jobOptions as $option)
                        <option value="{{ $option }}" @selected($filters['job'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="skill" class="mb-1 block text-xs font-semibold text-[#3A424C]">スキル（スキル欄に含む）</label>
                <input id="skill" name="skill" type="search" value="{{ $filters['skill'] }}" placeholder="例: Laravel" class="w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]">
            </div>
            <div>
                <label for="keyword" class="mb-1 block text-xs font-semibold text-[#3A424C]">キーワード（全文に含む）</label>
                <input id="keyword" name="keyword" type="search" value="{{ $filters['keyword'] }}" placeholder="例: リーダー" class="w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]">
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-md bg-[#2457C5] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1A3F8F]">絞り込む</button>
            @if ($filtered)
                <a href="{{ route('candidates.index') }}" class="text-sm text-[#2457C5] hover:underline">条件をクリア</a>
                <span class="text-sm text-[#5B6470]" data-testid="match-count">{{ count($rows) }}名が該当</span>
            @endif
            <span class="text-xs text-[#5B6470]">大文字・小文字は区別しません。意味の近さで探すときは「検索」画面を使ってください</span>
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg border border-[#E3E6EA] bg-white">
        <table class="w-full min-w-[880px] border-collapse text-sm">
            <thead>
                <tr class="bg-[#F6F7F9] text-left text-xs text-[#5B6470]">
                    <th scope="col" class="px-4 py-3 font-semibold">コード</th>
                    <th scope="col" class="px-4 py-3 font-semibold">名前</th>
                    <th scope="col" class="px-4 py-3 font-semibold">希望職種</th>
                    <th scope="col" class="px-4 py-3 font-semibold">文書</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">チャンク数</th>
                    <th scope="col" class="px-4 py-3 font-semibold">取り込み日時</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">操作</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $candidate = $row['candidate'];
                        $types = $candidate->documents->pluck('type')->all();
                    @endphp
                    <tr class="border-t border-[#E3E6EA]" data-testid="candidate-row">
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[13px]">{{ $candidate->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('candidates.show', $candidate) }}" class="font-semibold hover:text-[#2457C5]">{{ $candidate->name }}</a>
                            {{-- スキルで絞り込んだときは、一致したスキル欄の行 --}}
                            @foreach ($row['skillLines'] as $line)
                                <div class="mt-1 text-xs text-[#3A424C]" data-testid="skill-line">{{ $line }}</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-[13px] text-[#3A424C]">{{ $row['desiredJob'] ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach (Document::CANDIDATE_TYPES as $type)
                                    @if (in_array($type, $types, true))
                                        <span class="rounded bg-[#EAF0FC] px-2 py-0.5 text-xs text-[#1A3F8F]">{{ Document::TYPE_LABELS[$type] }}</span>
                                    @else
                                        <span class="rounded bg-[#F6F7F9] px-2 py-0.5 text-xs text-[#8A929C] line-through">{{ Document::TYPE_LABELS[$type] }}なし</span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-[13px]" data-testid="chunk-count">{{ $candidate->chunks_count }}</td>
                        {{-- 文書のうち一番新しい取り込み日時。文書がなければ — --}}
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[13px] text-[#3A424C]">{{ $candidate->documents->max('created_at')?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <a href="{{ route('candidates.show', $candidate) }}" class="inline-flex min-h-[36px] items-center rounded-md border border-[#C9CED5] px-3 text-[13px] font-semibold hover:border-[#2457C5]">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t border-[#E3E6EA]"><td colspan="7" class="px-4 py-5 text-[#5B6470]">該当する候補者がいません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
