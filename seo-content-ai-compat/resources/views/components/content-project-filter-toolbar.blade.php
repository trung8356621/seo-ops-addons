@props([
    'contentManager' => false,
    'variant' => 'content_project',
])

@php
    $isPublishingQueue = $variant === 'publishing_queue';
@endphp

<div
    {{ $attributes->class(['cp-ops-toolbar']) }}
    x-data="{ filtersOpen: false }"
>
    <div class="cp-ops-toolbar__row">
        <form wire:submit="applySearch" class="cp-ops-toolbar__search-form contents">
            <input
                type="search"
                wire:model="searchInput"
                placeholder="{{ __('seo-content-ai::filament.projects.queue_search') }}"
                class="fi-input cp-ops-toolbar__search"
                aria-label="{{ __('seo-content-ai::filament.projects.queue_search') }}"
                autocomplete="off"
            />
        </form>

        <div class="cp-ops-toolbar__filters">
            @if ($isPublishingQueue)
                <x-select wire:model.live="stateFilter" wrapClass="cp-ops-select" aria-label="Publish state filter">
                    <option value="">All</option>
                    <option value="unscheduled">Chưa lên lịch</option>
                    <option value="scheduled">Đã lên lịch</option>
                    <option value="awaiting_delivery">Chờ xử lý</option>
                    <option value="publishing">Đang xuất bản</option>
                    <option value="retry_wait">Thử lại sau</option>
                    <option value="published">Đã xuất bản</option>
                    <option value="failed">Không thể xuất bản</option>
                    <option value="needs_attention">Cần xử lý</option>
                </x-select>
            @else
                <x-select wire:model.live="workflowFilter" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.ops_workflow_filter') }}">
                    <option value="">{{ __('seo-content-ai::filament.projects.ops_workflow_all') }}</option>
                    <option value="normal">{{ __('seo-content-ai::filament.projects.ops_normal') }}</option>
                    @unless ($contentManager)
                        <option value="pending">{{ __('seo-content-ai::filament.projects.ops_pending') }}</option>
                    @endunless
                    <option value="recently_completed">{{ __('seo-content-ai::filament.projects.ops_needs_review') }}</option>
                    <option value="in_review_reporting">{{ __('seo-content-ai::filament.projects.ops_in_review') }}</option>
                    @unless ($contentManager)
                        <option value="failed">{{ __('seo-content-ai::filament.projects.ops_failed') }}</option>
                    @endunless
                </x-select>
                @unless ($contentManager)
                    <x-select wire:model.live="generationFilter" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.ops_col_generation') }}">
                        <option value="">{{ __('seo-content-ai::filament.projects.ops_col_generation') }}</option>
                        <option value="pending">pending</option>
                        <option value="running">running</option>
                        <option value="success">generated</option>
                        <option value="failed">failed</option>
                    </x-select>
                @endunless
            @endif
            <button type="button" wire:click="clearFilters" class="cp-ops-toolbar__link">
                Clear filters
            </button>
        </div>

        <button
            type="button"
            class="cp-ops-toolbar__filters-btn"
            @click="filtersOpen = true"
            aria-label="Open filters"
        >
            <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
            Filters
        </button>

        @if ($isPublishingQueue || ! $contentManager)
            <button
                type="button"
                @if ($isPublishingQueue)
                    @click="$store.pqOpsUi.selectPage()"
                @else
                    wire:click="selectPage"
                @endif
                class="fi-btn fi-btn-color-gray fi-size-sm cp-ops-toolbar__select-page"
                aria-label="{{ __('seo-content-ai::filament.projects.queue_select_page') }}"
            >
                {{ __('seo-content-ai::filament.projects.queue_select_page') }}
            </button>
        @endif
    </div>

    <div
        x-show="filtersOpen"
        x-cloak
        class="cp-ops-filters-drawer"
        @keydown.escape.window="filtersOpen = false"
    >
        <div class="cp-ops-filters-drawer__panel" @click.outside="filtersOpen = false">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Filters</h3>
                <button type="button" class="text-xs text-primary-600" @click="filtersOpen = false">Close</button>
            </div>
            <div class="space-y-3">
                @if ($isPublishingQueue)
                    <x-select wire:model.live="stateFilter" class="!w-full">
                        <option value="">All</option>
                        <option value="unscheduled">Unscheduled</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="publishing">Publishing</option>
                        <option value="published">Published</option>
                        <option value="failed">Failed</option>
                    </x-select>
                @else
                    <x-select wire:model.live="workflowFilter" class="!w-full">
                        <option value="">{{ __('seo-content-ai::filament.projects.ops_workflow_all') }}</option>
                        <option value="normal">{{ __('seo-content-ai::filament.projects.ops_normal') }}</option>
                        @unless ($contentManager)
                            <option value="pending">{{ __('seo-content-ai::filament.projects.ops_pending') }}</option>
                        @endunless
                        <option value="recently_completed">{{ __('seo-content-ai::filament.projects.ops_needs_review') }}</option>
                        <option value="in_review_reporting">{{ __('seo-content-ai::filament.projects.ops_in_review') }}</option>
                        @unless ($contentManager)
                            <option value="failed">{{ __('seo-content-ai::filament.projects.ops_failed') }}</option>
                        @endunless
                    </x-select>
                    @unless ($contentManager)
                        <x-select wire:model.live="generationFilter" class="!w-full">
                            <option value="">{{ __('seo-content-ai::filament.projects.ops_col_generation') }}</option>
                            <option value="pending">pending</option>
                            <option value="running">running</option>
                            <option value="success">generated</option>
                            <option value="failed">failed</option>
                        </x-select>
                    @endunless
                @endif
                <button type="button" wire:click="clearFilters" class="text-sm text-primary-600">Clear filters</button>
            </div>
        </div>
    </div>
</div>
