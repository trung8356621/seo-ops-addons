@php
    $state = (string) ($status['state'] ?? '');
    $isCritical = $state === 'critical';
    $isRescue = $state === 'rescue';
    $message = (string) ($status['message'] ?? '');
    $actionUrl = (string) ($status['action_url'] ?? '');
@endphp

@if ($isCritical || $isRescue)
    <div
        class="omi-ai-capacity-rail w-full shrink-0 px-4 pt-3 md:px-6 lg:px-8"
        role="{{ $isCritical ? 'alert' : 'status' }}"
        aria-live="{{ $isCritical ? 'assertive' : 'polite' }}"
        aria-atomic="true"
    >
        <div
            @class([
                'flex flex-col gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm shadow-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4',
                'border-red-400 bg-red-50 text-red-950 dark:border-red-700 dark:bg-red-950/90 dark:text-red-50' => $isCritical,
                'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-700 dark:bg-amber-950/90 dark:text-amber-50' => $isRescue,
            ])
        >
            <div class="min-w-0">
                <div
                    @class([
                        'font-semibold tracking-wide',
                        'text-red-900 dark:text-red-100' => $isCritical,
                        'text-amber-950 dark:text-amber-100' => $isRescue,
                    ])
                >
                    {{ $isCritical ? 'AI CRITICAL' : 'AI RESCUE MODE' }}
                </div>
                @if ($message !== '')
                    <p
                        @class([
                            'mt-0.5 leading-snug',
                            'text-red-800 dark:text-red-200' => $isCritical,
                            'text-amber-900 dark:text-amber-200' => $isRescue,
                        ])
                    >
                        {{ $message }}
                    </p>
                @endif
            </div>

            @if ($actionUrl !== '')
                <a
                    href="{{ $actionUrl }}"
                    @class([
                        'inline-flex shrink-0 items-center justify-center rounded-md px-3 py-2 text-xs font-semibold underline-offset-2 transition hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                        'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600 dark:bg-red-600 dark:hover:bg-red-500' => $isCritical,
                        'bg-amber-900 text-white hover:bg-amber-950 focus-visible:ring-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600' => $isRescue,
                    ])
                >
                    Mở API Connections
                </a>
            @endif
        </div>
    </div>
@endif
