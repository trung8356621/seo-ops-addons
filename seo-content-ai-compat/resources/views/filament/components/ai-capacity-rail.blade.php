<div
    @if (($status['state'] ?? '') === 'rescue' || ($status['state'] ?? '') === 'critical')
        class="omi-ai-capacity-rail sticky top-0 z-40 w-full border-b px-4 py-3 text-sm
            {{ ($status['state'] ?? '') === 'critical'
                ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100'
                : 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100' }}"
        role="status"
        aria-live="polite"
    @else
        class="hidden"
    @endif
>
    @if (($status['state'] ?? '') === 'rescue' || ($status['state'] ?? '') === 'critical')
        <div class="mx-auto flex max-w-7xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="font-semibold">
                    {{ ($status['state'] ?? '') === 'critical' ? 'AI CRITICAL' : 'AI RESCUE MODE' }}
                </div>
                <p class="mt-0.5">{{ $status['message'] ?? '' }}</p>
            </div>
            @if (! empty($status['action_url']))
                <a
                    href="{{ $status['action_url'] }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white dark:bg-white dark:text-gray-900"
                >
                    Kiểm tra API Connections
                </a>
            @endif
        </div>
    @endif
</div>
