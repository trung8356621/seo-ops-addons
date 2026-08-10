<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('seo-content-ai::filament.extensions.subtitle') }}
            </p>
            <button
                type="button"
                wire:click="refreshHealth"
                wire:loading.attr="disabled"
                wire:target="refreshHealth"
                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
            >
                <svg wire:loading wire:target="refreshHealth" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span wire:loading.remove wire:target="refreshHealth">{{ __('seo-content-ai::filament.extensions.refresh_health') }}</span>
                <span wire:loading wire:target="refreshHealth">{{ __('seo-content-ai::filament.common.saving') }}</span>
            </button>
        </div>

        @include('seo-content-ai::filament.pages.partials.runtime-info-grid', ['runtimeRows' => $rows])
    </div>
</x-filament-panels::page>
