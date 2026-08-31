@php
    $preflight = $siteSyncPreflight ?? null;
    $open = (bool) ($siteSyncPreflightOpen ?? false);
    $loading = (bool) ($siteSyncPreflightLoading ?? false);
    $wp = is_array($preflight['wordpress'] ?? null) ? $preflight['wordpress'] : [];
    $ops = is_array($preflight['seo_ops'] ?? null) ? $preflight['seo_ops'] : [];
    $delta = is_array($preflight['count_delta'] ?? null) ? $preflight['count_delta'] : [];
    $fields = is_array($preflight['data_health']['fields'] ?? null) ? $preflight['data_health']['fields'] : [];
    $tech = is_array($preflight['technical'] ?? null) ? $preflight['technical'] : [];
    $lastSync = is_array($preflight['last_sync'] ?? null) ? $preflight['last_sync'] : [];
    $recommend = (string) ($preflight['recommendation'] ?? 'normal_sync');
    $recommendFull = $recommend === 'full_sync';
    $recommendSynced = $recommend === 'synced';
    $countRows = [
        'total' => 'Total',
        'post' => 'Post',
        'page' => 'Page',
        'product' => 'Product',
    ];
@endphp

<div
    class="site-sync-preflight"
    wire:key="site-sync-preflight-{{ md5(json_encode([$preflight['site_id'] ?? 0, $preflight['severity'] ?? '', $open ? 1 : 0])) }}"
>
    @if ($loading)
        <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-[13px] text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <span class="inline-flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                Đang kiểm tra trước khi đồng bộ…
            </span>
        </div>
    @endif

    @if ($open && is_array($preflight))
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="site-sync-preflight-title"
    >
        <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
            <div class="shrink-0 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-800">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 id="site-sync-preflight-title" class="text-[15px] font-semibold text-gray-900 dark:text-gray-100">
                        Site sync preflight
                    </h3>
                    <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">
                        Kiểm tra dữ liệu WordPress và SEO Ops trước khi đồng bộ.
                    </p>
                </div>
                <button
                    type="button"
                    class="text-[13px] font-semibold text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                    wire:click="closeSiteSyncPreflight"
                >
                    Đóng
                </button>
            </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 sm:px-5">
            <div class="mb-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="mb-2 text-[13px] font-semibold text-gray-800 dark:text-gray-100">WordPress vs SEO Ops</p>
                @if (! ($wp['available'] ?? false) && filled($wp['message'] ?? null))
                    <p class="mb-2 text-[12px] text-amber-700 dark:text-amber-300">{{ $wp['message'] }}</p>
                @endif

                {{-- Desktop comparison table --}}
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full min-w-[28rem] border-collapse text-[12px] tabular-nums text-gray-700 dark:text-gray-200">
                        <thead>
                            <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                                <th class="py-1.5 pr-3 font-semibold text-gray-500 dark:text-gray-400">Type</th>
                                <th class="py-1.5 px-2 text-right font-semibold">WordPress</th>
                                <th class="py-1.5 px-2 text-right font-semibold">SEO Ops</th>
                                <th class="py-1.5 pl-2 text-right font-semibold">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($countRows as $key => $label)
                                @php
                                    $wpN = (int) ($wp[$key] ?? 0);
                                    $opsN = (int) ($ops[$key] ?? 0);
                                    $d = (int) ($delta[$key] ?? ($wpN - $opsN));
                                    $diffLabel = $d === 0 ? '0' : (($d > 0 ? '+' : '').$d);
                                    $diffHint = match (true) {
                                        $d > 0 => 'thiếu ở SEO Ops',
                                        $d < 0 => 'dư / stale / lệch type',
                                        default => '',
                                    };
                                @endphp
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-1.5 pr-3 font-medium text-gray-600 dark:text-gray-300">{{ $label }}</td>
                                    <td class="py-1.5 px-2 text-right">{{ number_format($wpN) }}</td>
                                    <td class="py-1.5 px-2 text-right">{{ number_format($opsN) }}</td>
                                    <td @class([
                                        'py-1.5 pl-2 text-right font-medium',
                                        'text-amber-700 dark:text-amber-300' => $d > 0,
                                        'text-sky-700 dark:text-sky-300' => $d < 0,
                                        'text-gray-500 dark:text-gray-400' => $d === 0,
                                    ])>
                                        <span title="{{ $diffHint }}">{{ $diffLabel }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile stacked rows — keep WP / SEO Ops / Diff relation --}}
                <div class="space-y-2 sm:hidden">
                    @foreach ($countRows as $key => $label)
                        @php
                            $wpN = (int) ($wp[$key] ?? 0);
                            $opsN = (int) ($ops[$key] ?? 0);
                            $d = (int) ($delta[$key] ?? ($wpN - $opsN));
                            $diffLabel = $d === 0 ? '0' : (($d > 0 ? '+' : '').$d);
                        @endphp
                        <div class="rounded-md border border-gray-100 px-2.5 py-2 dark:border-gray-800">
                            <p class="mb-1.5 text-[12px] font-semibold text-gray-700 dark:text-gray-200">{{ $label }}</p>
                            <div class="grid grid-cols-3 gap-2 text-[11px] tabular-nums">
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">WordPress</p>
                                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ number_format($wpN) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">SEO Ops</p>
                                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ number_format($opsN) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Difference</p>
                                    <p @class([
                                        'font-medium',
                                        'text-amber-700 dark:text-amber-300' => $d > 0,
                                        'text-sky-700 dark:text-sky-300' => $d < 0,
                                        'text-gray-500 dark:text-gray-400' => $d === 0,
                                    ])>{{ $diffLabel }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @php
                    $hasPositive = collect($countRows)->keys()->contains(fn ($k) => (int) ($delta[$k] ?? 0) > 0);
                    $hasNegative = collect($countRows)->keys()->contains(fn ($k) => (int) ($delta[$k] ?? 0) < 0);
                @endphp
                @if ($hasPositive || $hasNegative)
                    <p class="mt-2 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                        @if ($hasPositive)
                            <span class="text-amber-700 dark:text-amber-300">Difference &gt; 0</span>: thiếu ở SEO Ops.
                        @endif
                        @if ($hasPositive && $hasNegative)
                            ·
                        @endif
                        @if ($hasNegative)
                            <span class="text-sky-700 dark:text-sky-300">Difference &lt; 0</span>: dư / stale / lệch phân loại.
                        @endif
                    </p>
                @endif
            </div>

            <div class="mb-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="mb-2 text-[13px] font-semibold text-gray-800 dark:text-gray-100">SEO Ops data health</p>

                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full min-w-[28rem] border-collapse text-[12px] tabular-nums text-gray-700 dark:text-gray-200">
                        <thead>
                            <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                                <th class="py-1.5 pr-3 font-semibold text-gray-500 dark:text-gray-400">Field</th>
                                <th class="py-1.5 px-2 text-right font-semibold text-gray-500 dark:text-gray-400">Coverage</th>
                                <th class="py-1.5 pl-2 text-right font-semibold text-gray-500 dark:text-gray-400">Missing</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fields as $field)
                                @php
                                    $sev = (string) ($field['severity'] ?? 'green');
                                    $missing = (int) ($field['missing'] ?? 0);
                                    $present = (int) ($field['present'] ?? 0);
                                    $total = (int) ($field['total'] ?? 0);
                                    $dotClass = match ($sev) {
                                        'red' => 'bg-danger-500',
                                        'yellow' => 'bg-amber-500',
                                        default => 'bg-success-500',
                                    };
                                @endphp
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-1.5 pr-3">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                                            {{ $field['label'] ?? $field['key'] ?? '' }}
                                        </span>
                                    </td>
                                    <td class="py-1.5 px-2 text-right">
                                        {{ number_format($present) }} / {{ number_format($total) }}
                                    </td>
                                    <td @class([
                                        'py-1.5 pl-2 text-right font-medium',
                                        'text-danger-700 dark:text-danger-300' => $sev === 'red' && $missing > 0,
                                        'text-amber-700 dark:text-amber-300' => $sev === 'yellow' && $missing > 0,
                                        'text-gray-500 dark:text-gray-400' => $missing === 0,
                                    ])>
                                        {{ number_format($missing) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="space-y-2 sm:hidden">
                    @foreach ($fields as $field)
                        @php
                            $sev = (string) ($field['severity'] ?? 'green');
                            $missing = (int) ($field['missing'] ?? 0);
                            $present = (int) ($field['present'] ?? 0);
                            $total = (int) ($field['total'] ?? 0);
                            $dotClass = match ($sev) {
                                'red' => 'bg-danger-500',
                                'yellow' => 'bg-amber-500',
                                default => 'bg-success-500',
                            };
                        @endphp
                        <div class="rounded-md border border-gray-100 px-2.5 py-2 dark:border-gray-800">
                            <p class="mb-1.5 inline-flex items-center gap-2 text-[12px] font-semibold text-gray-700 dark:text-gray-200">
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                                {{ $field['label'] ?? $field['key'] ?? '' }}
                            </p>
                            <div class="grid grid-cols-2 gap-2 text-[11px] tabular-nums">
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Coverage</p>
                                    <p class="font-medium">{{ number_format($present) }} / {{ number_format($total) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Missing</p>
                                    <p @class([
                                        'font-medium',
                                        'text-danger-700 dark:text-danger-300' => $sev === 'red' && $missing > 0,
                                        'text-amber-700 dark:text-amber-300' => $sev === 'yellow' && $missing > 0,
                                        'text-gray-500 dark:text-gray-400' => $missing === 0,
                                    ])>{{ number_format($missing) }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div @class([
                'mb-4 rounded-lg border px-3 py-2.5 text-[13px]',
                'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100' => $recommendFull,
                'border-success-200 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-200' => $recommendSynced || (! $recommendFull && ($preflight['severity'] ?? '') === 'green'),
                'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100' => ! $recommendFull && ! $recommendSynced && ($preflight['severity'] ?? '') !== 'green',
            ])>
                <p class="font-semibold">{{ $preflight['recommendation_label'] ?? 'Khuyến nghị: Đồng bộ thay đổi' }}</p>
                <p class="mt-0.5 leading-relaxed">{{ $preflight['recommendation_message'] ?? '' }}</p>
                <p class="mt-1 text-[12px] opacity-80">Không tự chạy sync — bạn chọn bên dưới.</p>
            </div>

            @if (filled($lastSync['last_success_label'] ?? null) || filled($lastSync['last_check_label'] ?? null))
                <div class="mb-4 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-[12px] text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                    @if (filled($lastSync['last_success_label'] ?? null))
                        <p>Lần đồng bộ thành công gần nhất: <span class="font-medium text-gray-800 dark:text-gray-100">{{ $lastSync['last_success_label'] }}</span></p>
                    @endif
                    @if (filled($lastSync['last_check_label'] ?? null))
                        <p @class(['mt-0.5' => filled($lastSync['last_success_label'] ?? null)])>
                            Lần kiểm tra gần nhất: <span class="font-medium text-gray-800 dark:text-gray-100">{{ $lastSync['last_check_label'] }}</span>
                        </p>
                    @endif
                </div>
            @endif

            <details class="mb-4 text-[12px] text-gray-500 dark:text-gray-400">
                <summary class="cursor-pointer font-medium">Technical details</summary>
                <ul class="mt-1.5 space-y-0.5 font-mono">
                    @forelse ($tech as $techKey => $techValue)
                        <li>{{ $techKey }}: {{ is_scalar($techValue) ? $techValue : json_encode($techValue) }}</li>
                    @empty
                        <li class="font-sans text-gray-400">Không có metadata kỹ thuật.</li>
                    @endforelse
                    @foreach ($fields as $field)
                        <li>{{ $field['technical_key'] ?? $field['key'] ?? '' }} · {{ $field['how_to_check'] ?? '' }}</li>
                    @endforeach
                </ul>
            </details>
            </div>

            <div class="shrink-0 border-t border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-800">
            <div class="flex flex-wrap gap-2">
                @if ($recommendFull)
                    <x-filament::button
                        type="button"
                        color="warning"
                        wire:click="confirmSiteSyncPreflightFull"
                        wire:loading.attr="disabled"
                        wire:target="confirmSiteSyncPreflightNormal,confirmSiteSyncPreflightFull"
                    >
                        <span wire:loading.remove wire:target="confirmSiteSyncPreflightFull">Đồng bộ toàn bộ</span>
                        <span wire:loading wire:target="confirmSiteSyncPreflightFull">Đang xếp hàng…</span>
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="confirmSiteSyncPreflightNormal"
                        wire:loading.attr="disabled"
                        wire:target="confirmSiteSyncPreflightNormal,confirmSiteSyncPreflightFull"
                    >
                        <span wire:loading.remove wire:target="confirmSiteSyncPreflightNormal">Đồng bộ thay đổi</span>
                        <span wire:loading wire:target="confirmSiteSyncPreflightNormal">Đang xếp hàng…</span>
                    </x-filament::button>
                @else
                    <x-filament::button
                        type="button"
                        color="success"
                        wire:click="confirmSiteSyncPreflightNormal"
                        wire:loading.attr="disabled"
                        wire:target="confirmSiteSyncPreflightNormal,confirmSiteSyncPreflightFull"
                    >
                        <span wire:loading.remove wire:target="confirmSiteSyncPreflightNormal">Đồng bộ thay đổi</span>
                        <span wire:loading wire:target="confirmSiteSyncPreflightNormal">Đang xếp hàng…</span>
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="confirmSiteSyncPreflightFull"
                        wire:loading.attr="disabled"
                        wire:target="confirmSiteSyncPreflightNormal,confirmSiteSyncPreflightFull"
                    >
                        <span wire:loading.remove wire:target="confirmSiteSyncPreflightFull">Đồng bộ toàn bộ</span>
                        <span wire:loading wire:target="confirmSiteSyncPreflightFull">Đang xếp hàng…</span>
                    </x-filament::button>
                @endif
                <x-filament::button
                    type="button"
                    color="gray"
                    outlined
                    wire:click="closeSiteSyncPreflight"
                >
                    Hủy
                </x-filament::button>
            </div>
            </div>
        </div>
    </div>
    @endif
</div>
