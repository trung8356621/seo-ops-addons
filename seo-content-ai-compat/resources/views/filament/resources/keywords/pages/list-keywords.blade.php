@php
    $keywordDetailPanelConfig = [
        'livewireId' => $this->getId(),
        'errorLabel' => __('seo-content-ai::filament.keyword.drawer_load_error'),
        'selectedKeywordId' => $this->selectedKeywordId,
    ];
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $showDictionaryChrome = ($this->parentId ?? null) === null;
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
        @elseif ($subheading = $this->getSubheading())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $subheading }}</p>
        @endif

        <script type="application/json" id="keyword-detail-panel-config">
            @json($keywordDetailPanelConfig)
        </script>

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

            <div wire:ignore>
                <div data-keyword-detail-backdrop class="keyword-detail-backdrop" aria-hidden="true"></div>

                <aside
                    data-keyword-detail-panel
                    class="keyword-detail-drawer is-hidden"
                    aria-label="{{ __('seo-content-ai::filament.keyword.drawer_panel_label') }}"
                    aria-hidden="true"
                >
                <div class="keyword-detail-drawer__inner flex h-full w-full min-h-0 flex-col">
                    <header class="keyword-detail-drawer__header flex-shrink-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="keyword-detail-drawer__eyebrow">
                                    {{ __('seo-content-ai::filament.keyword.drawer_title') }}
                                </p>
                                <div data-keyword-detail-phrase-wrap class="hidden">
                                    <h2
                                        data-keyword-detail-phrase
                                        class="keyword-detail-drawer__phrase"
                                    ></h2>
                                </div>
                                <p data-keyword-detail-empty class="keyword-detail-drawer__placeholder">
                                    {{ __('seo-content-ai::filament.keyword.drawer_empty_state') }}
                                </p>
                            </div>

                            <button
                                type="button"
                                data-keyword-detail-close
                                class="keyword-detail-drawer__close"
                                aria-label="{{ __('seo-content-ai::filament.keyword.drawer_close') }}"
                            >
                                <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                            </button>
                        </div>

                        <div data-keyword-detail-quick-actions class="keyword-detail-drawer__quick-actions hidden">
                            <x-filament::button
                                data-keyword-detail-edit
                                size="sm"
                                color="gray"
                                icon="heroicon-m-pencil-square"
                                wire:click="editSelectedKeyword"
                                class="hidden"
                            >
                                {{ __('seo-content-ai::filament.keyword.edit') }}
                            </x-filament::button>

                            <x-filament::button
                                data-keyword-detail-move
                                size="sm"
                                color="gray"
                                icon="heroicon-m-arrows-right-left"
                                wire:click="moveSelectedKeyword"
                                class="hidden"
                            >
                                {{ __('seo-content-ai::filament.keyword.drawer_move') }}
                            </x-filament::button>

                            <x-filament::button
                                data-keyword-detail-delete
                                size="sm"
                                color="danger"
                                icon="heroicon-m-trash"
                                wire:click="deleteSelectedKeyword"
                                class="hidden"
                            >
                                {{ __('seo-content-ai::filament.keyword.delete') }}
                            </x-filament::button>
                        </div>
                    </header>

                    <div class="keyword-detail-drawer__body min-h-0 flex-1 overflow-y-auto">
                        <div data-keyword-detail-loading class="hidden px-5 py-8">
                            @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-detail-loading')
                            <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.keyword.drawer_loading') }}
                            </p>
                        </div>

                        <p
                            data-keyword-detail-error
                            class="keyword-detail-drawer__error hidden"
                        ></p>

                        <div data-keyword-detail-content class="keyword-detail-drawer__content hidden"></div>
                    </div>

                    <footer data-keyword-detail-footer class="keyword-detail-drawer__footer hidden">
                        <x-filament::button
                            data-keyword-detail-footer-edit
                            size="md"
                            color="gray"
                            icon="heroicon-m-pencil-square"
                            wire:click="editSelectedKeyword"
                            class="keyword-detail-drawer__footer-btn hidden w-full"
                        >
                            {{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}
                        </x-filament::button>

                        <x-filament::button
                            tag="a"
                            data-keyword-detail-analyze
                            size="md"
                            color="primary"
                            icon="heroicon-m-chart-bar-square"
                            href="#"
                            target="_blank"
                            class="keyword-detail-drawer__footer-btn hidden w-full pointer-events-none opacity-50"
                        >
                            {{ __('seo-content-ai::filament.keyword.drawer_analyze_content') }}
                        </x-filament::button>
                    </footer>
                </div>
            </aside>
            </div>
        </div>
    </div>

    @vite('addons/seo/resources/js/keyword-detail-panel.jsx')

    <x-filament-actions::modals />
</x-filament-panels::page>
