@php
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    $keyword = $this->selectedKeyword;
    $hasSelection = $this->selectedKeywordId !== null && $keyword instanceof \Omnichannel\Addons\SearchFoundation\Models\Keyword;
    $canEdit = $hasSelection && KeywordResource::canEdit($keyword);
    $canDelete = $hasSelection && KeywordResource::canDelete($keyword);
    $canMove = $hasSelection && SeoAccessControl::canAccessPlannerFeatures();
@endphp

<header class="flex-shrink-0 border-b border-gray-200 px-4 py-4 dark:border-white/10">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            @if ($hasSelection)
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.phrase_short') }}
                </p>
                <h2 class="mt-1 break-words text-lg font-bold text-gray-950 dark:text-white">
                    {{ $keyword->phrase }}
                </h2>
            @else
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.drawer_empty_state') }}
                </h2>
            @endif
        </div>

        <button
            type="button"
            wire:click="closeSidebar"
            class="inline-flex shrink-0 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
            aria-label="{{ __('seo-content-ai::filament.keyword.drawer_close') }}"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
        </button>
    </div>

    @if ($hasSelection)
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canEdit)
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-pencil-square"
                    wire:click="editSelectedKeyword"
                >
                    {{ __('seo-content-ai::filament.keyword.edit') }}
                </x-filament::button>
            @endif

            @if ($canMove)
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-arrows-right-left"
                    wire:click="moveSelectedKeyword"
                >
                    {{ __('seo-content-ai::filament.keyword.drawer_move') }}
                </x-filament::button>
            @endif

            @if ($canDelete)
                <x-filament::button
                    size="sm"
                    color="danger"
                    icon="heroicon-m-trash"
                    wire:click="deleteSelectedKeyword"
                >
                    {{ __('seo-content-ai::filament.keyword.delete') }}
                </x-filament::button>
            @endif
        </div>
    @endif
</header>

<div class="flex-1 overflow-y-auto px-4 py-4">
    @if (! $hasSelection)
        <div class="flex min-h-[14rem] flex-col items-center justify-center px-2 py-8 text-center">
            <x-filament::icon
                icon="heroicon-o-cursor-arrow-rays"
                class="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600"
            />
            <p class="max-w-xs text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.drawer_empty_state') }}
            </p>
        </div>
    @else
        @include('seo-content-ai::filament.resources.keywords.columns.keyword-link-flow-panel', [
            'record' => $keyword,
        ])
    @endif
</div>
