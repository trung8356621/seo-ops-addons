@php
    /** @var list<array<string, mixed>> $alerts */
    $alerts = $alerts ?? [];
    $scrollTarget = $scrollTarget ?? '#dashboard-usage-detail';
@endphp

<section class="h-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a3 3 0 0 1-5.714 0" />
                </svg>
            </span>
            <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Cảnh báo &amp; thông báo</h2>
        </div>
        <a
            href="{{ $scrollTarget }}"
            class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
            onclick="event.preventDefault(); document.querySelector('{{ $scrollTarget }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' });"
        >
            Xem tất cả
            <span aria-hidden="true">→</span>
        </a>
    </div>

    <div class="p-3 sm:p-4">
        @if (count($alerts) === 0)
            <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50/60 p-3 text-sm dark:border-gray-800 dark:bg-gray-950/30">
                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
                <div>
                    <div class="font-semibold text-gray-950 dark:text-white">Hệ thống hoạt động ổn định</div>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Không có lỗi nghiêm trọng.</p>
                </div>
            </div>
        @else
            <div class="max-h-[23rem] space-y-2 overflow-y-auto pr-1">
                @foreach (array_slice($alerts, 0, 6) as $alert)
                    @php
                        $severity = (string) ($alert['severity'] ?? '');
                        $tone = $severity === 'critical'
                            ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'
                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300';
                    @endphp
                    <article class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-950/30">
                        <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $tone }}">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75M12 15.75h.007v.008H12v-.008ZM10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                            </svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $alert['title'] }}</div>
                                @if (! empty($alert['action_url']))
                                    <a href="{{ $alert['action_url'] }}" class="shrink-0 text-xs font-semibold text-primary-600 hover:text-primary-500">
                                        {{ $alert['action_label'] ?? 'Xem' }}
                                    </a>
                                @endif
                            </div>
                            <p class="mt-0.5 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ $alert['message'] }}</p>
                            <div class="mt-1.5 text-[11px] text-gray-400">{{ $alert['detected_at_humans'] ?? '' }}</div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
