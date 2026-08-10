@props([
    'status' => 'idle',
    'message' => null,
    'loadingTarget' => null,
    'loadingLabel' => null,
])

@php
    $toneClass = match ($status) {
        'running' => 'text-primary-600 dark:text-primary-400',
        'completed' => 'text-success-600 dark:text-success-400',
        'failed' => 'text-danger-600 dark:text-danger-400',
        'resumable' => 'text-warning-600 dark:text-warning-400',
        default => 'text-gray-500 dark:text-gray-400',
    };

    $displayMessage = filled($message)
        ? $message
        : __('seo-content-ai::filament.domain.sync_action_status_ready');
@endphp

<div class="min-h-[2.75rem] flex items-center sm:justify-end">
    @if ($loadingTarget !== null && $loadingLabel !== null)
        <p
            wire:loading
            wire:target="{{ $loadingTarget }}"
            class="text-sm text-gray-500 dark:text-gray-400 sm:text-right"
        >
            {{ $loadingLabel }}
        </p>
    @endif

    <p
        @if ($loadingTarget !== null) wire:loading.remove wire:target="{{ $loadingTarget }}" @endif
        class="text-sm sm:text-right {{ $toneClass }}"
    >
        @if ($status === 'running')
            <span class="inline-flex items-center justify-end gap-1.5">
                <svg class="h-4 w-4 animate-spin shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ $displayMessage }}</span>
            </span>
        @else
            {{ $displayMessage }}
        @endif
    </p>
</div>
