{{-- 記録（queries への保存）に失敗したとき。結果は捨てずに表示し、履歴に残らないことを知らせる（RS-005 と同じ考え方） --}}
@if ($result->recordError !== null)
    <div role="alert" class="rounded-lg border border-[#F0D58A] bg-[#FFF8E1] p-4 text-sm text-[#6B4A00]" data-testid="record-error">
        結果を履歴に記録できませんでした（{{ $result->recordError }}）。結果は表示していますが、履歴には残りません。
    </div>
@endif
