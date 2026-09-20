@php
    $tokenTrend = $tokenTrend ?? $this->tokenDailyTrend();
    $useApex = $useApex ?? true;
    $pointCount = count($tokenTrend['labels'] ?? []);
    $hasData = $pointCount > 0 && (int) ($tokenTrend['max_tokens'] ?? 0) > 0;
@endphp

<section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Xu hướng tiêu thụ token theo ngày</h3>
        <div class="flex flex-wrap items-center gap-3 text-[11px] text-gray-500">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-sky-500"></span>Tổng</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>SEO</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-violet-500"></span>Seeding</span>
        </div>
    </div>

    <div class="p-3 sm:p-4">
        @if (! $hasData)
            <div class="flex h-[260px] items-center justify-center text-xs text-gray-400">
                Chưa có lượt sử dụng AI nào trong khoảng thời gian đã chọn.
            </div>
        @elseif ($useApex)
            <div id="admin-dashboard-token-trend" class="w-full min-h-[260px]" wire:ignore></div>
        @endif
    </div>
</section>
