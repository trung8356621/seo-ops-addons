@php
    $tokenTable = $tokenTable ?? $this->tokenTableData();
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
    $totalTokens = max(0, (int) ($tokenSummary['total_tokens'] ?? 0));
    $showRatio = $showRatio ?? true;
    $compact = $compact ?? true;
    $scrollTarget = $scrollTarget ?? null;
@endphp

<section
    class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full"
    x-data="{
        openAddons: { seo: true, seeding: true, unknown: true },
        toggleAddon(key) { this.openAddons[key] = !this.openAddons[key]; }
    }"
>
    <div class="flex flex-col gap-1 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Chi tiết token theo Addon / Module</h3>
            <p class="text-[11px] text-gray-500">Nhấp Addon để mở Module con.</p>
        </div>
        @if ($scrollTarget)
            <a
                href="{{ $scrollTarget }}"
                class="text-xs font-medium text-primary-600 hover:text-primary-500"
                onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
            >
                Xem chi tiết →
            </a>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/75 text-gray-600 dark:border-gray-800 dark:bg-gray-800/50 dark:text-gray-300 font-medium">
                    <th class="py-2 px-3">Addon / Module</th>
                    <th class="py-2 px-3 text-right">Calls</th>
                    <th class="py-2 px-3 text-right {{ $compact ? 'hidden md:table-cell' : '' }}">Input</th>
                    <th class="py-2 px-3 text-right {{ $compact ? 'hidden md:table-cell' : '' }}">Output</th>
                    <th class="py-2 px-3 text-right font-semibold">Total</th>
                    @if ($showRatio)
                        <th class="py-2 px-3 text-right">Tỷ lệ</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                @forelse ($tokenTable as $addonRow)
                    @php
                        $aKey = $addonRow['key'];
                        $ratio = $totalTokens > 0
                            ? round(((int) $addonRow['total_tokens'] / $totalTokens) * 100, 1)
                            : 0.0;
                    @endphp
                    <tr
                        class="cursor-pointer bg-gray-50/40 font-semibold text-gray-900 transition hover:bg-gray-100/60 dark:bg-gray-800/20 dark:text-white dark:hover:bg-gray-800/40"
                        @click="toggleAddon('{{ $aKey }}')"
                    >
                        <td class="py-2.5 px-3">
                            <div class="flex items-center gap-1.5">
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
                                <span class="text-[10px] font-normal text-gray-400">({{ count($addonRow['children']) }})</span>
                            </div>
                        </td>
                        <td class="py-2.5 px-3 text-right font-mono">{{ number_format($addonRow['calls']) }}</td>
                        <td class="py-2.5 px-3 text-right font-mono text-gray-600 {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($addonRow['input_tokens']) }}</td>
                        <td class="py-2.5 px-3 text-right font-mono text-gray-600 {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($addonRow['output_tokens']) }}</td>
                        <td class="py-2.5 px-3 text-right font-mono font-bold text-sky-600 dark:text-sky-400">{{ number_format($addonRow['total_tokens']) }}</td>
                        @if ($showRatio)
                            <td class="py-2.5 px-3 text-right font-mono text-gray-500">{{ number_format($ratio, 1) }}%</td>
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
                            <td class="py-1.5 px-3 pl-8 text-gray-600 dark:text-gray-400">↳ {{ $modRow['name'] }}</td>
                            <td class="py-1.5 px-3 text-right font-mono">{{ number_format($modRow['calls']) }}</td>
                            <td class="py-1.5 px-3 text-right font-mono {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($modRow['input_tokens']) }}</td>
                            <td class="py-1.5 px-3 text-right font-mono {{ $compact ? 'hidden md:table-cell' : '' }}">{{ number_format($modRow['output_tokens']) }}</td>
                            <td class="py-1.5 px-3 text-right font-mono">{{ number_format($modRow['total_tokens']) }}</td>
                            @if ($showRatio)
                                <td class="py-1.5 px-3 text-right font-mono text-gray-400">{{ number_format($modRatio, 1) }}%</td>
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
