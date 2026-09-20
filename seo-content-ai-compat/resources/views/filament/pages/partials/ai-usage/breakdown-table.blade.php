@php
    $tokenTable = $tokenTable ?? $this->tokenTableData();
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
    $totalTokens = max(0, (int) ($tokenSummary['total_tokens'] ?? 0));
    $showRatio = $showRatio ?? true;
    $compact = $compact ?? true;
    $scrollTarget = $scrollTarget ?? null;
@endphp

<section
    class="h-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
    x-data="{
        openAddons: { seo: true, seeding: true, unknown: true },
        toggleAddon(key) { this.openAddons[key] = !this.openAddons[key]; }
    }"
>
    <div class="flex flex-col gap-1 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Chi tiết token theo Addon / Module</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Nhấp vào từng Addon để xem chi tiết các Model và Action.</p>
        </div>
        @if ($scrollTarget)
            <a
                href="{{ $scrollTarget }}"
                class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-500"
                onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
            >
                Xem chi tiết
                <span aria-hidden="true">→</span>
            </a>
        @endif
    </div>

    <div class="overflow-x-auto p-3 sm:p-4">
        <table class="w-full min-w-full border-separate border-spacing-0 overflow-hidden rounded-lg text-left text-xs ring-1 ring-gray-200 dark:ring-gray-800">
            <thead>
                <tr class="bg-gray-50 text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    <th class="px-3 py-2.5 font-semibold">Addon / Module</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Calls</th>
                    <th class="px-3 py-2.5 text-right font-semibold {{ $compact ? 'hidden md:table-cell' : '' }}">Input tokens</th>
                    <th class="px-3 py-2.5 text-right font-semibold {{ $compact ? 'hidden md:table-cell' : '' }}">Output tokens</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Total tokens</th>
                    @if ($showRatio)
                        <th class="px-3 py-2.5 text-right font-semibold">Tỷ lệ</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800/70 dark:bg-gray-900">
                @forelse ($tokenTable as $addonRow)
                    @php
                        $aKey = $addonRow['key'];
                        $ratio = $totalTokens > 0
                            ? round(((int) $addonRow['total_tokens'] / $totalTokens) * 100, 1)
                            : 0.0;
                    @endphp
                    <tr
                        class="cursor-pointer bg-gray-50/50 font-semibold text-gray-950 transition hover:bg-gray-100 dark:bg-gray-800/20 dark:text-white dark:hover:bg-gray-800/40"
                        @click="toggleAddon('{{ $aKey }}')"
                    >
                        <td class="px-3 py-2.5">
                            <div class="flex items-center gap-2">
                                <svg
                                    class="h-3.5 w-3.5 text-gray-400 transition-transform"
                                    :class="{ 'rotate-90': openAddons['{{ $aKey }}'] }"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                    stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                                <span>{{ $addonRow['name'] }}</span>
                                <span class="text-[11px] font-normal text-gray-400">({{ count($addonRow['children']) }} module)</span>
                            </div>
                        </td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ number_format($addonRow['calls']) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-gray-600 dark:text-gray-300 {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($addonRow['input_tokens']) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-gray-600 dark:text-gray-300 {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($addonRow['output_tokens']) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono font-bold text-gray-950 dark:text-white">{{ number_format($addonRow['total_tokens']) }}</td>
                        @if ($showRatio)
                            <td class="px-3 py-2.5 text-right font-mono text-gray-600 dark:text-gray-300">{{ number_format($ratio, 1) }}%</td>
                        @endif
                    </tr>

                    @foreach ($addonRow['children'] as $modRow)
                        @php
                            $modRatio = $totalTokens > 0
                                ? round(((int) $modRow['total_tokens'] / $totalTokens) * 100, 1)
                                : 0.0;
                        @endphp
                        <tr
                            x-show="openAddons['{{ $aKey }}']"
                            class="text-gray-700 dark:text-gray-300"
                        >
                            <td class="px-3 py-2 pl-9 text-gray-600 dark:text-gray-400">{{ $modRow['name'] }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($modRow['calls']) }}</td>
                            <td class="px-3 py-2 text-right font-mono {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($modRow['input_tokens']) }}</td>
                            <td class="px-3 py-2 text-right font-mono {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($modRow['output_tokens']) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($modRow['total_tokens']) }}</td>
                            @if ($showRatio)
                                <td class="px-3 py-2 text-right font-mono text-gray-400">{{ number_format($modRatio, 1) }}%</td>
                            @endif
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="{{ $showRatio ? 6 : 5 }}" class="px-4 py-8 text-center text-gray-400">
                            Không có dữ liệu token nào trong khoảng thời gian đã chọn.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
