@extends('layouts.app')

@section('title', '簡易RAG 求人票一覧')

@php use App\Http\Controllers\JobController; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold">求人票一覧</h1>
            <p class="text-sm text-[#5B6470]">取り込み済み {{ $jobCount }}件・合計 {{ $chunkTotal }}チャンク</p>
        </div>
        {{-- 職種名で絞り込み（GET。JS は使わない） --}}
        <form method="GET" action="{{ route('jobs.index') }}" class="flex w-full max-w-sm items-end gap-2">
            <div class="flex-1">
                <label for="q" class="mb-1 block text-xs font-semibold text-[#3A424C]">職種名で絞り込み</label>
                <input id="q" name="q" type="search" value="{{ $filter }}" placeholder="例: エンジニア" class="w-full rounded-md border border-[#C9CED5] bg-white px-3 py-2 text-sm focus:border-[#2457C5] focus:outline-none focus:ring-2 focus:ring-[#EAF0FC]">
            </div>
            <button type="submit" class="rounded-md border border-[#C9CED5] bg-white px-4 py-2 text-sm font-semibold hover:border-[#2457C5]">絞り込む</button>
        </form>
    </div>

    <div class="overflow-x-auto rounded-lg border border-[#E3E6EA] bg-white">
        <table class="w-full min-w-[880px] border-collapse text-sm">
            <thead>
                <tr class="bg-[#F6F7F9] text-left text-xs text-[#5B6470]">
                    <th scope="col" class="px-4 py-3 font-semibold">職種名</th>
                    <th scope="col" class="px-4 py-3 font-semibold">主な必須スキル</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">チャンク数</th>
                    <th scope="col" class="px-4 py-3 font-semibold">取り込み日時</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">操作</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr class="border-t border-[#E3E6EA]" data-testid="job-row">
                        <td class="px-4 py-3">
                            <a href="{{ route('jobs.show', $job) }}" class="font-semibold hover:text-[#2457C5]">{{ JobController::jobTitle($job) }}</a>
                            <div class="font-mono text-xs text-[#5B6470]">{{ $job->source_path }}</div>
                        </td>
                        <td class="px-4 py-3 text-[13px] text-[#3A424C]">{{ JobController::mainRequirement($job) ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono text-[13px]">{{ $job->chunks_count }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[13px] text-[#3A424C]">{{ $job->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <a href="{{ route('jobs.show', $job) }}" class="inline-flex min-h-[36px] items-center rounded-md bg-[#2457C5] px-3 text-[13px] font-semibold text-white hover:bg-[#1A3F8F]">詳細・合う候補者を探す</a>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t border-[#E3E6EA]"><td colspan="5" class="px-4 py-5 text-[#5B6470]">該当する求人票がありません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
