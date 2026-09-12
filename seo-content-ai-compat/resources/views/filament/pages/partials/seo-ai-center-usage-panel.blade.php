@php
    $walletCards = $this->walletCards();
    $tokenSummary = $this->tokenSummary();
    $tokenTrend = $this->tokenDailyTrend();
    $tokenTable = $this->tokenTableData();
@endphp

<div
    id="ai-center-usage"
    class="seo-ai-panel space-y-8"
    x-show="activeMainTab === 'usage'"
    style="display: none;"
    wire:key="ai-center-usage"
    x-data="{
        openAddons: {
            seo: true,
            seeding: true,
            unknown: true
        },
        toggleAddon(key) {
            this.openAddons[key] = !this.openAddons[key];
        },
        hoveredIndex: null
    }"
>
    {{-- PHẦN 1: PROVIDER WALLET --}}
    <section class="space-y-4">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
                    </svg>
                    Ví nhà cung cấp (Provider Wallet)
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Số dư thực tế do API chính thức của provider trả về. Không ước tính, không quy đổi giả định.
                </p>
            </div>
            <div>
                <x-filament::button
                    wire:click="$refresh"
                    size="sm"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    wire:loading.attr="disabled"
                    wire:target="$refresh"
                >
                    Làm mới danh sách
                </x-filament::button>
            </div>
        </div>

        @if (count($walletCards) === 0)
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center text-sm text-gray-500">
                Chưa có connection AI nào được cấu hình trong hệ thống.
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($walletCards as $card)
                    <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 transition hover:shadow-md">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $card['name'] }}
                                    </h3>
                                    <span class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                        {{ $card['provider_label'] }}
                                    </span>
                                </div>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $card['status_badge_class'] }}">
                                    {{ $card['status_label'] }}
                                </span>
                            </div>

                            <div class="mt-4">
                                @if ($card['supported'])
                                    @if ($card['balance'] !== null)
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                                                {{ $card['currency'] === 'USD' ? '$' : $card['currency'].' ' }}{{ number_format((float) $card['balance'], 2) }}
                                            </span>
                                            <span class="text-xs text-gray-400">{{ $card['currency'] }}</span>
                                        </div>
                                    @else
                                        <div class="text-lg font-medium text-gray-400 dark:text-gray-500">
                                            —
                                        </div>
                                    @endif
                                @else
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 italic">
                                        Không hỗ trợ balance API
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800/80 pt-2.5">
                                <span>Ngưỡng cảnh báo: <strong>${{ number_format($card['threshold'], 2) }}</strong></span>
                                <button
                                    type="button"
                                    wire:click="openEditThresholdModal({{ $card['id'] }})"
                                    class="text-primary-600 hover:text-primary-500 text-[11px] font-medium"
                                >
                                    Sửa ngưỡng
                                </button>
                            </div>

                            @if ($card['status'] === 'check_failed' && ! empty($card['error']))
                                <div class="mt-2 text-[11px] text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 p-2 rounded border border-red-100 dark:border-red-900/30 line-clamp-2" title="{{ $card['error'] }}">
                                    Lỗi: {{ $card['error'] }}
                                </div>
                            @endif
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t border-gray-100 dark:border-gray-800/80 pt-3">
                            <span class="text-[11px] text-gray-400" title="{{ $card['checked_at_exact'] ?? '' }}">
                                {{ $card['checked_at'] ? 'Đã kiểm tra ' . $card['checked_at'] : 'Chưa kiểm tra' }}
                            </span>

                            @if ($card['supported'])
                                <x-filament::button
                                    wire:click="refreshConnectionBalance({{ $card['id'] }})"
                                    size="xs"
                                    color="gray"
                                    icon="heroicon-o-arrow-path"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshConnectionBalance({{ $card['id'] }})"
                                >
                                    <span wire:loading.remove wire:target="refreshConnectionBalance({{ $card['id'] }})">Refresh</span>
                                    <span wire:loading wire:target="refreshConnectionBalance({{ $card['id'] }})">Đang lấy...</span>
                                </x-filament::button>
                            @else
                                <span class="text-[11px] text-gray-400">—</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- MODAL SỬA NGƯỠNG CẢNH BÁO --}}
    @if ($editingThresholdConnectionId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-950/50 backdrop-blur-sm" wire:key="threshold-modal">
            <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Cấu hình ngưỡng cảnh báo số dư</h3>
                <p class="mt-1 text-xs text-gray-500">Hệ thống sẽ phát cảnh báo trên Dashboard khi số dư thấp hơn ngưỡng này.</p>
                <div class="mt-4">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Ngưỡng cảnh báo ($ USD)</label>
                    <div class="relative mt-1">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                        <input
                            type="number"
                            step="0.5"
                            min="0"
                            wire:model="editingThresholdValue"
                            class="block w-full rounded-lg border-gray-300 pl-7 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        />
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <x-filament::button color="gray" size="sm" wire:click="closeEditThresholdModal">
                        Hủy
                    </x-filament::button>
                    <x-filament::button size="sm" wire:click="saveThreshold">
                        Lưu ngưỡng
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- PHẦN 2: TOKEN USAGE --}}
    <section class="space-y-4 pt-4 border-t border-gray-200 dark:border-gray-800">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605" />
                    </svg>
                    Lượng token sử dụng (Token Usage)
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Thống kê mức độ sử dụng AI của các Addon / Module theo physical routing attempts.
                </p>
            </div>

            {{-- TOOLBAR BỘ LỌC --}}
            <div class="flex flex-wrap items-center gap-2">
                <select
                    wire:model.live="usageDateRange"
                    class="rounded-lg border-gray-300 text-xs py-1.5 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
                    <option value="today">Hôm nay</option>
                    <option value="7d">7 ngày qua</option>
                    <option value="30d">30 ngày qua</option>
                    <option value="this_month">Tháng này</option>
                </select>

                <select
                    wire:model.live="usageAddonFilter"
                    class="rounded-lg border-gray-300 text-xs py-1.5 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
                    <option value="all">Tất cả Addons</option>
                    <option value="seo">SEO</option>
                    <option value="seeding">Seeding</option>
                </select>
            </div>
        </div>

        {{-- SUMMARY CARDS --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">SEO Addon</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ number_format($tokenSummary['seo']['tokens']) }}
                    </span>
                    <span class="text-xs text-gray-500">tokens</span>
                </div>
                <div class="mt-2 text-xs text-gray-500 border-t border-gray-100 dark:border-gray-800 pt-2">
                    {{ number_format($tokenSummary['seo']['calls']) }} lượt gọi API
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Seeding Addon</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ number_format($tokenSummary['seeding']['tokens']) }}
                    </span>
                    <span class="text-xs text-gray-500">tokens</span>
                </div>
                <div class="mt-2 text-xs text-gray-500 border-t border-gray-100 dark:border-gray-800 pt-2">
                    {{ number_format($tokenSummary['seeding']['calls']) }} lượt gọi API
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng tokens</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                        {{ number_format($tokenSummary['total_tokens']) }}
                    </span>
                    <span class="text-xs text-gray-500">tokens</span>
                </div>
                <div class="mt-2 text-xs text-gray-500 border-t border-gray-100 dark:border-gray-800 pt-2">
                    {{ number_format($tokenSummary['total_calls']) }} lượt gọi API tổng cộng
                </div>
            </div>
        </div>

        {{-- CHART TREND THEO NGÀY --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Xu hướng tiêu thụ token theo ngày</h3>
                <div class="flex items-center gap-4 text-xs">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-sm bg-indigo-500"></span>
                        <span class="text-gray-600 dark:text-gray-400">SEO</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-sm bg-emerald-500"></span>
                        <span class="text-gray-600 dark:text-gray-400">Seeding</span>
                    </span>
                </div>
            </div>

            @php
                $pointCount = count($tokenTrend['labels']);
                $maxTokens = max(1, $tokenTrend['max_tokens']);
            @endphp

            @if ($pointCount === 0 || $tokenTrend['max_tokens'] === 0)
                <div class="py-12 text-center text-xs text-gray-400">
                    Chưa có lượt sử dụng AI nào trong khoảng thời gian đã chọn.
                </div>
            @else
                <div class="mt-4 relative">
                    {{-- Tooltip overlay --}}
                    <div
                        x-show="hoveredIndex !== null"
                        class="absolute top-0 right-0 z-10 rounded-lg border border-gray-200 bg-white/95 px-3 py-2 text-xs shadow-lg backdrop-blur dark:border-gray-700 dark:bg-gray-800/95"
                        style="display: none;"
                    >
                        <div class="font-semibold text-gray-900 dark:text-white" x-text="labels[hoveredIndex] + ' (' + dates[hoveredIndex] + ')'"></div>
                        <div class="mt-1 space-y-0.5">
                            <div class="text-indigo-600 dark:text-indigo-400">
                                SEO: <strong x-text="Number(seoSeries[hoveredIndex] || 0).toLocaleString()"></strong> tokens
                            </div>
                            <div class="text-emerald-600 dark:text-emerald-400">
                                Seeding: <strong x-text="Number(seedingSeries[hoveredIndex] || 0).toLocaleString()"></strong> tokens
                            </div>
                            <div class="pt-1 border-t border-gray-200 dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200">
                                Tổng ngày: <strong x-text="Number(totalSeries[hoveredIndex] || 0).toLocaleString()"></strong> tokens
                            </div>
                        </div>
                    </div>

                    {{-- Bar Chart SVG --}}
                    <div
                        class="w-full overflow-x-auto"
                        x-data="{
                            labels: @js($tokenTrend['labels']),
                            dates: @js($tokenTrend['dates']),
                            seoSeries: @js($tokenTrend['seo_series']),
                            seedingSeries: @js($tokenTrend['seeding_series']),
                            totalSeries: @js($tokenTrend['total_series']),
                            maxTokens: {{ $maxTokens }}
                        }"
                    >
                        <div class="min-w-[600px] h-48 flex items-end gap-1.5 pt-6 pb-6 px-2 border-b border-gray-200 dark:border-gray-800">
                            @foreach ($tokenTrend['labels'] as $idx => $label)
                                @php
                                    $seo = $tokenTrend['seo_series'][$idx] ?? 0;
                                    $seeding = $tokenTrend['seeding_series'][$idx] ?? 0;
                                    $total = $tokenTrend['total_series'][$idx] ?? 0;
                                    $seoPercent = ($seo / $maxTokens) * 100;
                                    $seedingPercent = ($seeding / $maxTokens) * 100;
                                @endphp
                                <div
                                    class="flex-1 flex flex-col justify-end items-center h-full group relative cursor-pointer"
                                    @mouseenter="hoveredIndex = {{ $idx }}"
                                    @mouseleave="hoveredIndex = null"
                                >
                                    <div class="w-full max-w-[28px] flex flex-col justify-end rounded-t overflow-hidden bg-gray-100 dark:bg-gray-800/40 h-full">
                                        <div
                                            class="w-full bg-emerald-500 transition-all duration-200 group-hover:brightness-110"
                                            style="height: {{ max(0, min(100, $seedingPercent)) }}%;"
                                        ></div>
                                        <div
                                            class="w-full bg-indigo-500 transition-all duration-200 group-hover:brightness-110"
                                            style="height: {{ max(0, min(100, $seoPercent)) }}%;"
                                        ></div>
                                    </div>
                                    <span class="mt-2 text-[10px] text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white truncate">
                                        {{ $label }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- TABLE BREAKDOWN THEO ADDON / MODULE --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Chi tiết theo Addon và Module</h3>
                <p class="text-xs text-gray-500">Nhấp vào từng Addon để xem chi tiết các Module con bên dưới.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50/75 dark:border-gray-800 dark:bg-gray-800/50 text-gray-600 dark:text-gray-300 font-medium">
                            <th class="py-2.5 px-4">Addon / Module</th>
                            <th class="py-2.5 px-4 text-right">Calls</th>
                            <th class="py-2.5 px-4 text-right">Input tokens</th>
                            <th class="py-2.5 px-4 text-right">Output tokens</th>
                            <th class="py-2.5 px-4 text-right font-semibold">Total tokens</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800/60">
                        @forelse ($tokenTable as $addonRow)
                            @php($aKey = $addonRow['key'])
                            {{-- Dòng cấp tổng: Addon --}}
                            <tr
                                class="bg-gray-50/40 dark:bg-gray-800/20 hover:bg-gray-100/60 dark:hover:bg-gray-800/40 cursor-pointer font-semibold text-gray-900 dark:text-white transition"
                                @click="toggleAddon('{{ $aKey }}')"
                            >
                                <td class="py-3 px-4 flex items-center gap-2">
                                    <button type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <svg
                                            class="w-4 h-4 transition-transform duration-150"
                                            :class="{ 'rotate-90': openAddons['{{ $aKey }}'] }"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="2"
                                            stroke="currentColor"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </button>
                                    <span>{{ $addonRow['name'] }}</span>
                                    <span class="text-[11px] font-normal text-gray-400">({{ count($addonRow['children']) }} modules)</span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono">{{ number_format($addonRow['calls']) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-gray-600 dark:text-gray-400">{{ number_format($addonRow['input_tokens']) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-gray-600 dark:text-gray-400">{{ number_format($addonRow['output_tokens']) }}</td>
                                <td class="py-3 px-4 text-right font-mono text-indigo-600 dark:text-indigo-400 font-bold">{{ number_format($addonRow['total_tokens']) }}</td>
                            </tr>

                            {{-- Các dòng cấp con: Module --}}
                            @foreach ($addonRow['children'] as $modRow)
                                <tr
                                    x-show="openAddons['{{ $aKey }}']"
                                    class="text-gray-700 dark:text-gray-300 hover:bg-gray-50/50 dark:hover:bg-gray-800/30"
                                >
                                    <td class="py-2 px-4 pl-10 text-gray-600 dark:text-gray-400 flex items-center gap-2">
                                        <span class="text-gray-300 dark:text-gray-600">↳</span>
                                        <span>{{ $modRow['name'] }}</span>
                                    </td>
                                    <td class="py-2 px-4 text-right font-mono {{ $modRow['calls'] === 0 ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ number_format($modRow['calls']) }}</td>
                                    <td class="py-2 px-4 text-right font-mono {{ $modRow['input_tokens'] === 0 ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ number_format($modRow['input_tokens']) }}</td>
                                    <td class="py-2 px-4 text-right font-mono {{ $modRow['output_tokens'] === 0 ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ number_format($modRow['output_tokens']) }}</td>
                                    <td class="py-2 px-4 text-right font-mono font-medium text-gray-900 dark:text-gray-200 {{ $modRow['total_tokens'] === 0 ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ number_format($modRow['total_tokens']) }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 px-4 text-center text-gray-400">
                                    Không có dữ liệu token nào trong khoảng thời gian đã chọn.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
