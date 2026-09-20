@php
    /** @var list<array<string, mixed>> $alerts */
    $alerts = $alerts ?? [];
    $scrollTarget = $scrollTarget ?? '#dashboard-usage-detail';
@endphp

<section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full">
    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <div class="flex items-center gap-2">
            <svg class="h-4 w-4 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Cảnh báo &amp; thông báo</h2>
        </div>
        <a
            href="{{ $scrollTarget }}"
            class="text-xs font-medium text-primary-600 hover:text-primary-500"
            onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
        >
            Xem chi tiết →
        </a>
    </div>

    <div class="p-3 sm:p-4">
        @if (count($alerts) === 0)
            <div class="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 text-xs text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-400">
                <svg class="mt-0.5 h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>Hệ thống ổn định — không có cảnh báo quan trọng.</span>
            </div>
        @else
            <div class="space-y-2.5 max-h-[22rem] overflow-y-auto pr-1">
                @foreach (array_slice($alerts, 0, 6) as $alert)
                    <div @class([
                        'rounded-lg border p-2.5 text-xs',
                        'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20' => ($alert['severity'] ?? '') === 'critical',
                        'border-amber-200 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20' => ($alert['severity'] ?? '') === 'warning',
                    ])>
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $alert['title'] }}</div>
                        <p class="mt-1 text-gray-600 dark:text-gray-300 line-clamp-3">{{ $alert['message'] }}</p>
                        <div class="mt-2 flex items-center justify-between gap-2 border-t border-gray-200/60 pt-1.5 dark:border-gray-800/60">
                            <span class="text-[10px] text-gray-400">{{ $alert['detected_at_humans'] ?? '' }}</span>
                            @if (! empty($alert['action_url']))
                                <a href="{{ $alert['action_url'] }}" class="font-semibold text-primary-600 hover:text-primary-500">
                                    {{ $alert['action_label'] ?? 'Xem' }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
