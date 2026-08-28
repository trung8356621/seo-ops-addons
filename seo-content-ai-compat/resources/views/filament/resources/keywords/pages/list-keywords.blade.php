@php
    $keywordDetailPanelConfig = [
        'livewireId' => $this->getId(),
        'errorLabel' => __('seo-content-ai::filament.keyword.drawer_load_error'),
        'selectedKeywordId' => $this->selectedKeywordId,
    ];
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $showDictionaryChrome = true;
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'keyword-dictionary-page',
        'keyword-master-detail-page',
        'max-w-full',
    ])
>
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-6">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        @if ($showDictionaryChrome)
            @if ($this->getKeywordWorkspaceMode() === 'focus')
                @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-focus-header')
            @else
                @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-dictionary-header')
            @endif
            @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-dictionary-stats')
            @if ($this->getKeywordWorkspaceMode() !== 'focus')
                @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-classification-summary')
            @endif
        @elseif ($subheading = $this->getSubheading())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $subheading }}</p>
        @endif

        <div class="keyword-detail-layout">
            <div class="keyword-table-shell min-w-0">
                @if ($showDictionaryChrome)
                    <div class="keyword-dictionary-toolbar">
                        <div class="keyword-dictionary-toolbar__actions">
                            <button
                                type="button"
                                class="ws-btn ws-btn--ghost keyword-dictionary-toolbar__filters-btn"
                                onclick="window.toggleKeywordDictionaryFilters?.()"
                            >
                                <x-filament::icon icon="heroicon-m-adjustments-horizontal" class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.advanced_filters') }}
                            </button>
                            <button
                                type="button"
                                class="ws-btn ws-btn--ghost"
                                onclick="document.querySelector('.keyword-dictionary-page .fi-ta-filters-trigger')?.click()"
                            >
                                <x-filament::icon icon="heroicon-m-bookmark" class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.save_filters') }}
                            </button>
                        </div>
                    </div>
                @endif

                <div class="keyword-dictionary-table-card">
                    {{ $this->table }}
                </div>
            </div>

            @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-detail-drawer', [
                'keywordDetailPanelConfig' => $keywordDetailPanelConfig,
            ])
        </div>
    </div>

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-move-cluster-modal')

    <x-filament-actions::modals />
</x-filament-panels::page>
