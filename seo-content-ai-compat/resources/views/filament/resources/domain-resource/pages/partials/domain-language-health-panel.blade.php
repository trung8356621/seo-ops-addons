@props([
    'snapshot' => null,
    'remoteLoading' => false,
])

@php
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $preflight = is_array($snapshot['preflight'] ?? null) ? $snapshot['preflight'] : [];
    $scoring = is_array($snapshot['scoring'] ?? null) ? $snapshot['scoring'] : [];
    $wp = is_array($preflight['wordpress'] ?? null) ? $preflight['wordpress'] : [];
    $ops = is_array($preflight['seo_ops'] ?? null) ? $preflight['seo_ops'] : [];
    $delta = is_array($preflight['count_delta'] ?? null) ? $preflight['count_delta'] : [];
    $fields = is_array($preflight['data_health']['fields'] ?? null) ? $preflight['data_health']['fields'] : [];
    $lastSync = is_array($preflight['last_sync'] ?? null) ? $preflight['last_sync'] : [];
    $remoteFetched = (bool) ($snapshot['remote_fetched'] ?? false);
    $countRows = [
        'total' => 'Total',
        'post' => 'Post',
        'page' => 'Page',
        'product' => 'Product',
    ];
@endphp

<div class="space-y-3 text-sm">
    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <p class="mb-2 text-[13px] font-semibold text-gray-800 dark:text-gray-100">WordPress vs SEO Ops</p>
        @if ($remoteLoading && ! $remoteFetched)
            <p class="text-[12px] text-gray-500 dark:text-gray-400">Đang lấy số liệu WordPress…</p>
        @elseif (! $remoteFetched)
            <p class="mb-2 text-[11px] text-gray-500 dark:text-gray-400">
                SEO Ops (local). WordPress sẽ cập nhật khi tab này được làm mới.
            </p>
            <div class="grid grid-cols-2 gap-2 text-[12px] tabular-nums sm:grid-cols-4">
                @foreach ($countRows as $key => $label)
                    <div>
                        <p class="text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="font-medium">SEO Ops: {{ number_format((int) ($ops[$key] ?? 0)) }}</p>
                    </div>
                @endforeach
            </div>
        @else
            @if (! ($wp['available'] ?? false) && filled($wp['message'] ?? null))
                <p class="mb-2 text-[12px] text-amber-700 dark:text-amber-300">{{ $wp['message'] }}</p>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full min-w-[24rem] border-collapse text-[12px] tabular-nums text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="py-1.5 pr-3 font-semibold text-gray-500">Type</th>
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
                            @endphp
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="py-1.5 pr-3 font-medium">{{ $label }}</td>
                                <td class="py-1.5 px-2 text-right">{{ number_format($wpN) }}</td>
                                <td class="py-1.5 px-2 text-right">{{ number_format($opsN) }}</td>
                                <td @class([
                                    'py-1.5 pl-2 text-right font-medium',
                                    'text-amber-700 dark:text-amber-300' => $d > 0,
                                    'text-sky-700 dark:text-sky-300' => $d < 0,
                                    'text-gray-500' => $d === 0,
                                ])>{{ $diffLabel }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <p class="mb-2 text-[13px] font-semibold text-gray-800 dark:text-gray-100">Data Health</p>
        @if ($fields === [])
            <p class="text-[12px] text-gray-500">Chưa có dữ liệu health cho ngôn ngữ này.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[28rem] border-collapse text-[12px] tabular-nums text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="py-1.5 pr-3 font-semibold text-gray-500">Field</th>
                            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Applicable</th>
                            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Present</th>
                            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Missing</th>
                            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">N/A</th>
                            <th class="py-1.5 pl-2 text-right font-semibold text-gray-500">Source absent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fields as $field)
                            @php
                                $sev = (string) ($field['severity'] ?? 'green');
                                $missing = (int) ($field['missing'] ?? 0);
                                $present = (int) ($field['raw_present'] ?? $field['present'] ?? 0);
                                $applicable = (int) ($field['applicable'] ?? $field['total'] ?? 0);
                                $na = (int) ($field['not_applicable'] ?? 0);
                                $sourceAbsent = (int) ($field['source_absent'] ?? 0);
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
                                <td class="py-1.5 px-2 text-right">{{ number_format($applicable) }}</td>
                                <td class="py-1.5 px-2 text-right">{{ number_format($present) }}</td>
                                <td @class([
                                    'py-1.5 px-2 text-right font-medium',
                                    'text-danger-700 dark:text-danger-300' => $sev === 'red' && $missing > 0,
                                    'text-amber-700 dark:text-amber-300' => $sev === 'yellow' && $missing > 0,
                                    'text-gray-500' => $missing === 0,
                                ])>{{ number_format($missing) }}</td>
                                <td class="py-1.5 px-2 text-right text-gray-500">{{ number_format($na) }}</td>
                                <td class="py-1.5 pl-2 text-right text-gray-500">{{ number_format($sourceAbsent) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if (filled($preflight['recommendation_label'] ?? null))
            <p class="mt-2 text-[12px] text-gray-600 dark:text-gray-300">
                <span class="font-medium">{{ $preflight['recommendation_label'] }}</span>
                — {{ $preflight['recommendation_message'] ?? '' }}
            </p>
        @endif
    </div>

    @if ($scoring !== [])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <p class="mb-1 text-[13px] font-semibold text-gray-800 dark:text-gray-100">SEO scoring</p>
            <p class="text-[12px] tabular-nums text-gray-600 dark:text-gray-300">
                Hoàn tất {{ number_format((int) ($scoring['completed'] ?? 0)) }}
                / {{ number_format((int) ($scoring['total'] ?? 0)) }}
                · còn lại {{ number_format((int) ($scoring['remaining'] ?? 0)) }}
            </p>
        </div>
    @endif

    @if (filled($lastSync['last_success_label'] ?? null) || filled($lastSync['last_check_label'] ?? null))
        <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-[12px] text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
            @if (filled($lastSync['last_success_label'] ?? null))
                <p>Lần đồng bộ gần nhất: <span class="font-medium text-gray-800 dark:text-gray-100">{{ $lastSync['last_success_label'] }}</span></p>
            @endif
            @if (filled($lastSync['last_check_label'] ?? null))
                <p @class(['mt-0.5' => filled($lastSync['last_success_label'] ?? null)])>
                    Kiểm tra: <span class="font-medium text-gray-800 dark:text-gray-100">{{ $lastSync['last_check_label'] }}</span>
                </p>
            @endif
        </div>
    @endif
</div>
