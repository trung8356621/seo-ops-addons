@php
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
    $tokenTable = $tokenTable ?? $this->tokenTableData();
    $useApex = $useApex ?? true;
    $scrollTarget = $scrollTarget ?? '#dashboard-usage-detail';
    $total = max(0, (int) ($tokenSummary['total_tokens'] ?? 0));

    $slices = [];
    foreach ($tokenTable as $row) {
        $tokens = (int) ($row['total_tokens'] ?? 0);
        if ($tokens <= 0) {
            continue;
        }
        $slices[] = [
            'name' => (string) ($row['name'] ?? ''),
            'tokens' => $tokens,
            'pct' => $total > 0 ? round(($tokens / $total) * 100, 1) : 0.0,
        ];
    }
@endphp

<section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full">
    <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Phân bổ token theo Module</h3>
        <a
            href="{{ $scrollTarget }}"
            class="text-xs font-medium text-primary-600 hover:text-primary-500"
            onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
        >
            Chi tiết →
        </a>
    </div>

    <div class="p-3 sm:p-4">
        @if ($total === 0 || count($slices) === 0)
            <div class="flex h-[260px] items-center justify-center text-xs text-gray-400">
                Chưa có dữ liệu phân bổ token.
            </div>
        @else
            @if ($useApex)
                <div id="admin-dashboard-token-donut" class="w-full min-h-[200px]" wire:ignore></div>
            @endif
            <ul class="mt-2 space-y-1.5 text-xs">
                @foreach ($slices as $slice)
                    <li class="flex items-center justify-between gap-2">
                        <span class="truncate text-gray-700 dark:text-gray-300">{{ $slice['name'] }}</span>
                        <span class="shrink-0 font-mono text-gray-500">
                            {{ number_format($slice['tokens']) }}
                            <span class="text-gray-400">({{ number_format($slice['pct'], 1) }}%)</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
