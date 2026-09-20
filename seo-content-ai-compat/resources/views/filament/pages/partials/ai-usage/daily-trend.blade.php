@php
    $tokenTrend = $tokenTrend ?? $this->tokenDailyTrend();
    $useApex = $useApex ?? true;
    $pointCount = count($tokenTrend['labels'] ?? []);
    $hasData = $pointCount > 0 && (int) ($tokenTrend['max_tokens'] ?? 0) > 0;
@endphp

<section class="h-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Xu hướng tiêu thụ token theo ngày</h3>
        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600 dark:text-gray-300">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>Tổng tokens</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>SEO Action</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-violet-500"></span>Seeding</span>
        </div>
    </div>

    <div class="p-3 sm:p-4">
        @if (! $hasData)
            <div class="flex h-[260px] items-center justify-center rounded-lg border border-dashed border-gray-300 text-sm text-gray-400 dark:border-gray-700">
                Chưa có lượt sử dụng AI nào trong khoảng thời gian đã chọn.
            </div>
        @elseif ($useApex)
            <div id="admin-dashboard-token-trend" class="w-full min-h-[260px]" wire:ignore></div>
        @endif
    </div>
</section>
