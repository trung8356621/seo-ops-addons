<div
    x-data="{ show: @entangle('selectedSuggestionIds').live }"
    x-show="show.length > 0"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="translate-y-full opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="translate-y-full opacity-0"
    x-cloak
    class="ai-discovery-action-bar"
>
    <div class="ai-discovery-action-bar__inner">
        <p class="text-sm font-semibold text-gray-900 dark:text-white">
            {{ __('seo-content-ai::filament.keyword.discovery_selected_count', ['count' => $selectedCount]) }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="batchImport"
                wire:loading.attr="disabled"
                wire:target="batchImport"
                class="ai-discovery-action-btn ai-discovery-action-btn--green"
            >
                <span wire:loading.remove wire:target="batchImport" class="inline-flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-arrow-down-tray" class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.discovery_batch_import') }}
                </span>
                <span wire:loading wire:target="batchImport" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('seo-content-ai::filament.keyword.discovery_importing') }}
                </span>
            </button>

            <button
                type="button"
                wire:click="createDraftArticles"
                wire:loading.attr="disabled"
                wire:target="createDraftArticles"
                class="ai-discovery-action-btn ai-discovery-action-btn--blue"
            >
                <span wire:loading.remove wire:target="createDraftArticles" class="inline-flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-document-plus" class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.discovery_create_drafts') }}
                </span>
                <span wire:loading wire:target="createDraftArticles" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('seo-content-ai::filament.keyword.discovery_creating_drafts') }}
                </span>
            </button>
        </div>
    </div>
</div>
