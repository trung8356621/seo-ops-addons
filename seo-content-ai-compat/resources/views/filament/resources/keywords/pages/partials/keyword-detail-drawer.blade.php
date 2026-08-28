<script type="application/json" id="keyword-detail-panel-config">
    @json($keywordDetailPanelConfig)
</script>

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
                <button
                    type="button"
                    data-keyword-detail-footer-edit
                    class="keyword-detail-drawer__footer-btn keyword-detail-drawer__footer-btn--edit hidden"
                >
                    <x-filament::icon icon="heroicon-m-pencil-square" class="h-5 w-5" />
                    {{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}
                </button>

                <a
                    data-keyword-detail-analyze
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="keyword-detail-drawer__footer-btn keyword-detail-drawer__footer-btn--analyze is-disabled hidden"
                    aria-disabled="true"
                    tabindex="-1"
                >
                    <x-filament::icon icon="heroicon-m-chart-bar-square" class="h-5 w-5" />
                    {{ __('seo-content-ai::filament.keyword.drawer_analyze_content') }}
                </a>
            </footer>
        </div>
    </aside>
</div>

@vite('addons/seo/resources/js/keyword-detail-panel.jsx')
