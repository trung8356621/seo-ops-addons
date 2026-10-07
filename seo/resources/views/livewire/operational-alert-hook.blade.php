<div data-operational-alert-hook>
    @if ($alerts !== [])
        <div class="mx-auto w-full max-w-screen-2xl space-y-3 px-4 pt-4 sm:px-6 lg:px-8">
            @foreach ($alerts as $alert)
                @php
                    $critical = in_array($alert['severity'], ['critical', 'danger'], true);
                    $warning = $alert['severity'] === 'warning';
                @endphp
                <section
                    class="flex flex-col gap-3 rounded-xl border border-l-4 px-4 py-3 shadow-sm sm:flex-row sm:items-center
                        {{ $critical
                            ? 'border-red-300 border-l-red-600 bg-red-100 dark:border-red-800 dark:border-l-red-500 dark:bg-red-950/60'
                            : ($warning
                                ? 'border-amber-200 border-l-amber-500 bg-amber-100 dark:border-amber-800 dark:border-l-amber-600 dark:bg-amber-950/35'
                                : 'border-sky-200 border-l-sky-500 bg-sky-50 dark:border-sky-800 dark:border-l-sky-500 dark:bg-sky-950/35') }}"
                    style="{{ $critical
                        ? 'background-color: #fee2e2; border-color: #fca5a5; border-left-color: #dc2626; color: #111827;'
                        : ($warning
                            ? 'background-color: #fef3c7; border-color: #fde68a; border-left-color: #f59e0b; color: #111827;'
                            : 'background-color: #e0f2fe; border-color: #7dd3fc; border-left-color: #0284c7; color: #111827;') }}"
                    role="alert"
                    data-operational-alert-id="{{ $alert['id'] }}"
                    data-severity="{{ $alert['severity'] }}"
                    data-event-code="{{ $alert['event_code'] }}"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg
                                {{ $critical ? 'bg-red-600 text-white' : ($warning ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700') }}"
                            style="{{ $critical
                                ? 'background-color: #dc2626; color: #ffffff;'
                                : ($warning ? 'background-color: #fef3c7; color: #b45309;' : 'background-color: #e0f2fe; color: #0369a1;') }}"
                        >
                            <x-filament::icon
                                icon="{{ $critical ? 'heroicon-o-fire' : ($warning ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-information-circle') }}"
                                class="h-5 w-5"
                            />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold sm:text-base" style="color: {{ $critical ? '#7f1d1d' : ($warning ? '#78350f' : '#0c4a6e') }};">
                                {{ $alert['title'] }}
                                @if ($alert['occurrence_count'] > 1)
                                    <span class="ml-1 text-xs font-semibold opacity-80">· {{ $alert['occurrence_count'] }} lần</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-sm" style="color: #374151;">{{ $alert['message'] }}</p>
                        </div>
                    </div>
                    @if (! empty($alert['action_url']))
                        <div class="flex shrink-0 items-center gap-2 pl-[3.25rem] sm:pl-0">
                            <a
                                href="{{ $alert['action_url'] }}"
                                class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-sm font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                                style="{{ $critical
                                    ? 'background-color: #dc2626; border-color: #dc2626; color: #ffffff;'
                                    : ($warning
                                        ? 'background-color: #ffffff; border-color: #f59e0b; color: #92400e;'
                                        : 'background-color: #ffffff; border-color: #0284c7; color: #075985;') }}"
                            >{{ $alert['action_label'] ?: 'Mở' }}</a>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
