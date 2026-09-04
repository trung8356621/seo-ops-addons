@props([
    'label' => null,
])

@php
    $label = is_string($label) && $label !== ''
        ? $label
        : __('seo-content-ai::filament.keyword.modal_loading_data');
@endphp

<div {{ $attributes->class(['modal-loading-placeholder space-y-4 py-1']) }} role="status" aria-live="polite">
    <div class="animate-pulse space-y-3" aria-hidden="true">
        <div class="h-3 w-28 rounded bg-gray-200 dark:bg-white/10"></div>
        <div class="h-9 w-full rounded-lg bg-gray-200 dark:bg-white/10"></div>
        <div class="h-3 w-36 rounded bg-gray-200 dark:bg-white/10"></div>
        <div class="h-16 w-full rounded-lg bg-gray-200 dark:bg-white/10"></div>
        <div class="h-3 w-32 rounded bg-gray-200 dark:bg-white/10"></div>
        <div class="h-20 w-full rounded-lg bg-gray-200 dark:bg-white/10"></div>
    </div>
    <p class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <x-filament::loading-indicator class="h-4 w-4" />
        <span>{{ $label }}</span>
    </p>
</div>
