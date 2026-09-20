@php
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
    $tokenTable = $tokenTable ?? $this->tokenTableData();
    $useApex = $useApex ?? true;
    $scrollTarget = $scrollTarget ?? '#dashboard-usage-detail';
    $total = max(0, (int) ($tokenSummary['total_tokens'] ?? 0));
    $palette = ['seo' => '#10b981', 'seeding' => '#8b5cf6', 'unknown' => '#94a3b8'];
    $fallback = ['#f59e0b', '#0ea5e9', '#84cc16', '#6366f1'];

    $slices = [];
    foreach ($tokenTable as $i => $row) {
        $tokens = (int) ($row['total_tokens'] ?? 0);
        $key = (string) ($row['key'] ?? '');
        $slices[] = [
            'name' => (string) ($row['name'] ?? $key),
            'tokens' => $tokens,
            'pct' => $total > 0 ? round(($tokens / $total) * 100, 1) : 0.0,
            'color' => $palette[$key] ?? $fallback[$i % count($fallback)],
        ];
    }
@endphp

<section class="h-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Phân bổ token theo Module</h3>
        <a
            href="{{ $scrollTarget }}"
            class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
            onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
        >
            Chi tiết
            <span aria-hidden="true">→</span>
        </a>
    </div>

    <div class="p-3 sm:p-4">
        @if ($total === 0 || count(array_filter($slices, fn ($slice) => (int) $slice['tokens'] > 0)) === 0)
            <div class="flex h-[260px] items-center justify-center rounded-lg border border-dashed border-gray-300 text-sm text-gray-400 dark:border-gray-700">
                Chưa có dữ liệu phân bổ token.
            </div>
        @else
            <div class="grid items-center gap-4 xl:grid-cols-2">
                @if ($useApex)
                    <div id="admin-dashboard-token-donut" class="min-h-[220px] w-full" wire:ignore></div>
                @endif
                <ul class="space-y-2 text-xs">
                    @foreach ($slices as $slice)
                        <li class="flex items-center gap-2">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $slice['color'] }}"></span>
                                <span class="truncate text-gray-700 dark:text-gray-300">{{ $slice['name'] }}</span>
                            </span>
                            <span class="ml-auto w-12 shrink-0 text-right font-mono text-gray-500">{{ number_format($slice['pct'], 1) }}%</span>
                            <span class="w-20 shrink-0 text-right font-mono font-semibold text-gray-900 dark:text-white">{{ number_format($slice['tokens']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</section>
