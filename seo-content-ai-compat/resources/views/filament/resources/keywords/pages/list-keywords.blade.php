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

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-view-mode-script')

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
        @elseif ($subheading = $this->getSubheading())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $subheading }}</p>
        @endif

        <x-seo-content-ai::list-table-loading-shell
            class="space-y-6"
            preset="filament-table"
            targets="onKeywordWorkspaceSiteFilterChanged,applyDictionaryStatFilter,dictionaryStatFilter,clusterKeyFilter,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId"
        >
            @if ($showDictionaryChrome)
                @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-dictionary-stats')
                @if ($this->getKeywordWorkspaceMode() !== 'focus')
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-classification-summary')
                @endif
            @endif

            <div
                id="keyword-detail-layout"
                class="keyword-detail-layout"
                data-keyword-view-mode="detail"
                x-data="keywordDictionaryViewMode(@js($this->getId()))"
                x-bind:data-keyword-view-mode="mode"
                x-bind:class="mode === 'quick' ? 'is-quick-mode' : 'is-detail-mode'"
            >
                <script>
                    (() => {
                        const el = document.getElementById('keyword-detail-layout');
                        if (!el) {
                            return;
                        }
                        let mode = 'detail';
                        try {
                            const stored = localStorage.getItem('seo_ops_keyword_view_mode');
                            if (stored === 'quick' || stored === 'detail') {
                                mode = stored;
                            }
                        } catch (_error) {
                            // ignore storage failures
                        }
                        el.setAttribute('data-keyword-view-mode', mode);
                        el.classList.toggle('is-quick-mode', mode === 'quick');
                        el.classList.toggle('is-detail-mode', mode === 'detail');
                    })();
                </script>

                <div class="keyword-table-shell min-w-0">
                    @if ($showDictionaryChrome)
                        <div class="keyword-dictionary-toolbar">
                            <div
                                class="keyword-view-mode-toggle"
                                role="group"
                                aria-label="{{ __('seo-content-ai::filament.keyword.view_mode_label') }}"
                            >
                                <button
                                    type="button"
                                    class="keyword-view-mode-toggle__btn"
                                    x-bind:class="{ 'is-active': mode === 'quick' }"
                                    x-bind:aria-pressed="mode === 'quick' ? 'true' : 'false'"
                                    title="{{ __('seo-content-ai::filament.keyword.view_mode_quick_hint') }}"
                                    @click="setMode('quick')"
                                >
                                    {{ __('seo-content-ai::filament.keyword.view_mode_quick') }}
                                </button>
                                <button
                                    type="button"
                                    class="keyword-view-mode-toggle__btn"
                                    x-bind:class="{ 'is-active': mode === 'detail' }"
                                    x-bind:aria-pressed="mode === 'detail' ? 'true' : 'false'"
                                    title="{{ __('seo-content-ai::filament.keyword.view_mode_detail_hint') }}"
                                    @click="setMode('detail')"
                                >
                                    {{ __('seo-content-ai::filament.keyword.view_mode_detail') }}
                                </button>
                            </div>
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

                    <div
                        class="keyword-dictionary-table-card"
                        data-keyword-quick-select-root
                        @mouseup="onResultsMouseUp($event)"
                    >
                        {{ $this->table }}
                    </div>
                </div>

                <div class="keyword-detail-drawer-slot">
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-detail-drawer', [
                        'keywordDetailPanelConfig' => $keywordDetailPanelConfig,
                    ])
                </div>
            </div>
        </x-seo-content-ai::list-table-loading-shell>
    </div>

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-move-cluster-modal')

    <x-filament-actions::modals />

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-quick-copy-script')
</x-filament-panels::page>
