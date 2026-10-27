@php
    /** @var array<string, mixed>|null $holder */
    $holder = is_array($holder ?? null) ? $holder : null;
    $unavailable = (bool) ($unavailable ?? false);
    $progressUrl = ! empty($holder['progress_url']) ? (string) $holder['progress_url'] : null;
    $busyMessage = \Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock::BUSY_MESSAGE;
    $unavailableMessage = __('seo-content-ai::filament.projects.progress_lock_unavailable');
    if ($unavailableMessage === 'seo-content-ai::filament.projects.progress_lock_unavailable') {
        $unavailableMessage = \Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock::UNAVAILABLE_MESSAGE;
    }
@endphp

@if ($unavailable)
    <div
        data-cp-lock-notice="unavailable"
        class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        role="alert"
    >
        <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
        <span class="truncate">{{ $unavailableMessage }}</span>
    </div>
@elseif ($holder !== null)
    <div
        data-cp-lock-notice="held"
        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
        role="alert"
    >
        <div class="flex items-center gap-2 min-w-0">
            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span class="font-medium truncate">{{ $busyMessage }}</span>
        </div>
        @if ($progressUrl)
            <a
                href="{{ $progressUrl }}"
                class="ms-auto inline-flex items-center gap-1 font-semibold text-amber-900 underline hover:text-amber-700 dark:text-amber-200 dark:hover:text-amber-100 shrink-0"
                data-cp-lock-progress-link
            >
                <span>{{ __('seo-content-ai::filament.projects.progress_view_active_progress') }}</span>
                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
            </a>
        @endif
    </div>
@endif
