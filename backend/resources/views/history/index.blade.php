@extends('layouts.app')

@section('title', '簡易RAG 履歴')

@php
    use App\Http\Controllers\JobController;
    use App\Models\Document;
    use App\Models\QueryLog;
    use App\Support\RagFormat;
    use Illuminate\Support\Str;

    $modeStyles = [
        QueryLog::MODE_QUESTION => 'bg-[#EAF0FC] text-[#1A3F8F]',
        QueryLog::MODE_JOB_SEARCH => 'bg-[#E1F3E7] text-[#1F6B3A]',
        QueryLog::MODE_JOB_MATCH => 'bg-[#FDEEDC] text-[#7A3E00]',
    ];
    // 条件の短い表記（例: 5名 / ≥0.3 / 職務経歴書 / 最大値 / 必須条件のみ）
    $conditions = function (QueryLog $log) {
        $p = $log->params ?? [];
        $unit = $log->mode === QueryLog::MODE_JOB_SEARCH ? '件' : '名';

        return implode(' / ', array_filter([
            isset($p['top_k']) ? $p['top_k'].$unit : null,
            isset($p['min_similarity']) ? '≥'.$p['min_similarity'] : null,
            $log->mode === QueryLog::MODE_JOB_SEARCH ? null : (Document::TYPE_LABELS[$p['document_type'] ?? ''] ?? '両方'),
            RagFormat::SCORE_LABELS[$p['score'] ?? ''] ?? null,
            JobController::SOURCE_LABELS[$p['source'] ?? ''] ?? null,
        ]));
    };
@endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-xl font-bold">質問の履歴</h1>
        <span class="text-sm text-[#5B6470]">同じ条件で再実行すると、設定やデータを変えた前後の結果を比べられます</span>
    </div>

    <div class="overflow-x-auto rounded-lg border border-[#E3E6EA] bg-white">
        <table class="w-full min-w-[1040px] border-collapse text-sm">
            <thead>
                <tr class="bg-[#F6F7F9] text-left text-xs text-[#5B6470]">
                    <th scope="col" class="px-4 py-3 font-semibold">日時</th>
                    <th scope="col" class="px-4 py-3 font-semibold">質問</th>
                    <th scope="col" class="px-4 py-3 font-semibold">モード</th>
                    <th scope="col" class="px-4 py-3 font-semibold">条件</th>
                    <th scope="col" class="px-4 py-3 font-semibold">1位（候補者／求人票）</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">スコア</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">該当</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">合計時間</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">操作</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    @php
                        $isJobSearch = $log->mode === QueryLog::MODE_JOB_SEARCH;
                        $top = $isJobSearch ? ($log->retrieved['jobs'][0] ?? null) : ($log->retrieved['candidates'][0] ?? null);
                        $topName = null;
                        $topCode = null;
                        if ($top !== null && $isJobSearch) {
                            $doc = $documents[$top['document_id']] ?? null;
                            [$topName, $topCode] = $doc ? [JobController::jobTitle($doc), basename($doc->source_path)] : ["文書 {$top['document_id']}（削除済み）", null];
                        } elseif ($top !== null) {
                            $candidate = $candidates[$top['candidate_id']] ?? null;
                            [$topName, $topCode] = [$candidate?->name ?? "候補者 {$top['candidate_id']}", $candidate?->code];
                        }
                        $question = $log->mode === QueryLog::MODE_JOB_MATCH
                            ? '求人票：'.($log->sourceDocument ? JobController::jobTitle($log->sourceDocument) : '（削除済み）')
                            : $log->question;
                    @endphp
                    <tr class="border-t border-[#E3E6EA]" data-testid="history-row">
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[13px] text-[#3A424C]">{{ $log->created_at?->format('m/d H:i') }}</td>
                        <td class="px-4 py-3 font-medium">{{ Str::limit($question, 60) }}</td>
                        <td class="whitespace-nowrap px-4 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $modeStyles[$log->mode] ?? '' }}">{{ QueryLog::MODE_LABELS[$log->mode] ?? $log->mode }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-[13px] text-[#3A424C]">{{ $conditions($log) }}</td>
                        <td class="px-4 py-3">
                            @if ($top)
                                <span class="font-semibold">{{ $topName }}</span> <span class="font-mono text-xs text-[#5B6470]">{{ $topCode }}</span>
                            @else
                                <span class="text-[#5B6470]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-[13px] font-semibold">{{ $top ? number_format($top['score'], 4) : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-[13px] text-[#3A424C]">{{ $log->retrieved['counts']['total_groups'] ?? '—' }}{{ $isJobSearch ? '件' : '名' }}</td>
                        <td class="px-4 py-3 text-right font-mono text-[13px]">{{ RagFormat::ms(RagFormat::total($log->latency_ms ?? [])) }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <a href="{{ $log->resultUrl() }}" class="inline-flex min-h-[36px] items-center rounded-md border border-[#C9CED5] px-3 text-[13px] font-semibold hover:border-[#2457C5]">詳細</a>
                            <form method="POST" action="{{ route('history.rerun', $log) }}" data-loading="実行中…" class="ml-1.5 inline">
                                @csrf
                                <button type="submit" class="inline-flex min-h-[36px] items-center rounded-md bg-[#2457C5] px-3 text-[13px] font-semibold text-white hover:bg-[#1A3F8F] disabled:opacity-50"
                                        @disabled($log->mode === QueryLog::MODE_JOB_MATCH && $log->sourceDocument === null)>再実行</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t border-[#E3E6EA]"><td colspan="9" class="px-4 py-5 text-[#5B6470]">まだ履歴がありません。質問画面か求人票詳細から実行すると、ここに残ります。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
    <p class="text-xs text-[#5B6470]">再実行は外部 API（埋め込み、候補者への質問では Claude も）を呼び、新しい履歴として記録します。</p>
</div>
@endsection
