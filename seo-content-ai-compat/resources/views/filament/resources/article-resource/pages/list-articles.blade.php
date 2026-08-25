@php
    $seoPreviewUrlTemplate = route('seo.articles.seo-preview', ['article' => '__ID__']);
    $overviewCss = base_path('addons/content/resources/css/domain-overview.css');
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @if(is_readable($overviewCss))
        <style>{!! file_get_contents($overviewCss) !!}</style>
    @endif

    <div
        class="seo-internal-tabs mb-4"
        @if ($this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_QUEUE)
            wire:poll.15s
        @endif
    >
        <a
            href="{{ $this->getContentTabUrl(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_POSTS) }}"
            @class(['is-active' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_POSTS])
        >
            {{ __('seo-content-ai::filament.article_list.tab_posts') }}
        </a>
        <a
            href="{{ $this->getContentTabUrl(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_CATEGORIES) }}"
            @class(['is-active' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_CATEGORIES])
        >
            {{ __('seo-content-ai::filament.article_list.tab_categories') }}
        </a>
        @php($syncQueueBadge = $this->getSyncQueueBadgeCount())
        <a
            href="{{ $this->getContentTabUrl(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_QUEUE) }}"
            @class([
                'seo-internal-tabs__queue',
                'is-active' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_QUEUE,
                'has-queue-items' => $syncQueueBadge > 0,
            ])
        >
            {{ __('seo-content-ai::filament.article_list.tab_queue') }}
            @if ($syncQueueBadge > 0)
                <span class="seo-internal-tabs__queue-badge" aria-label="{{ $syncQueueBadge }}">{{ $syncQueueBadge }}</span>
            @endif
        </a>
        <a
            href="{{ $this->getContentTabUrl(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_REVIEWED) }}"
            @class(['is-active' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_REVIEWED])
        >
            {{ __('seo-content-ai::filament.article_list.tab_reviewed') }}
        </a>
        <a
            href="{{ $this->getContentTabUrl(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_SKIPPED) }}"
            @class(['is-active' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_SKIPPED])
        >
            {{ __('seo-content-ai::filament.article_list.tab_skipped') }}
        </a>
        <a
            href="{{ \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl() }}"
            class="seo-internal-tabs__audit"
        >
            {{ __('seo-content-ai::filament.projects.seo_audit_nav_label') }}
        </a>
    </div>

    @if ($this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_REVIEWED)
        @include('seo-content-ai::filament.resources.article-resource.pages.partials.reviewed-articles-tab')
    @else
    <div @class([
        'article-list-table-shell',
        'article-list-table-shell--queue' => $this->contentTab === \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles::TAB_QUEUE,
    ])>
        <div
            class="article-list-table-shell__overlay"
            role="status"
            aria-live="polite"
        >
            <x-filament::loading-indicator class="h-8 w-8" />
            <span class="article-list-table-shell__overlay-text">
                {{ __('seo-content-ai::filament.article_list.table_loading') }}
            </span>
        </div>
        {{ $this->table }}
    </div>
    @endif

    <script type="application/json" id="article-seo-list-config">
        @json([
            'previewUrlTemplate' => $seoPreviewUrlTemplate,
        ])
    </script>

    <div
        id="article-seo-modal"
        class="article-seo-modal"
        aria-hidden="true"
        aria-labelledby="article-seo-modal-title"
        role="dialog"
    >
        <div class="article-seo-modal__backdrop" data-article-seo-modal-close></div>
        <div class="article-seo-modal__dialog">
            <header class="article-seo-modal__header">
                <div class="article-seo-modal__header-text">
                    <h2 id="article-seo-modal-title" class="article-seo-modal__title">SEO point</h2>
                    <p id="article-seo-modal-subtitle" class="article-seo-modal__subtitle"></p>
                </div>
                <button
                    type="button"
                    class="article-seo-modal__icon-close"
                    data-article-seo-modal-close
                    aria-label="Đóng"
                >
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
            </header>

            <div class="article-seo-modal__body">
                <div id="article-seo-modal-loading" class="article-seo-modal__loading article-seo-modal__loading--hidden">
                    <x-filament::loading-indicator class="h-8 w-8" />
                    <span>Đang tải phân tích SEO…</span>
                </div>
                <p id="article-seo-modal-error" class="article-seo-modal__error article-seo-modal__error--hidden"></p>
                <div id="seo-article-preview-modal-root" class="seo-article-preview-modal-root"></div>
            </div>

            <footer class="article-seo-modal__footer">
                <button type="button" class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray fi-btn-size-md fi-btn-outlined gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20" data-article-seo-modal-close>
                    Đóng
                </button>
                <a
                    id="article-seo-modal-edit"
                    href="#"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 article-seo-modal__edit--hidden"
                >
                    Sửa bài viết
                </a>
            </footer>
        </div>
    </div>

    @viteReactRefresh
    @vite('addons/seo/resources/js/article-seo-preview.jsx')

    @once
        <style>
                .article-list-table-shell {
                    position: relative;
                }

                .article-list-table-shell.is-table-loading > :not(.article-list-table-shell__overlay) {
                    opacity: 0.45;
                    pointer-events: none;
                    transition: opacity 0.12s ease;
                }

                .article-list-table-shell__overlay {
                    display: none;
                    position: absolute;
                    inset: 0;
                    z-index: 25;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                    background: rgb(255 255 255 / 72%);
                    pointer-events: none;
                }

                .article-list-table-shell.is-table-loading .article-list-table-shell__overlay {
                    display: flex;
                }

                .dark .article-list-table-shell__overlay {
                    background: rgb(17 24 39 / 72%);
                }

                .article-list-table-shell__overlay-text {
                    font-size: 0.8125rem;
                    font-weight: 600;
                    color: rgb(55 65 81);
                }

                .dark .article-list-table-shell__overlay-text {
                    color: rgb(209 213 219);
                }

                /* Cột thao tác: 2 hàng × 3 nút */
                .fi-resource-articles .fi-ta-actions-header-cell {
                    width: auto !important;
                    min-width: 5.75rem;
                }

                .fi-resource-articles .fi-ta-actions-cell > div {
                    white-space: normal;
                    padding-top: 0.5rem;
                    padding-bottom: 0.5rem;
                }

                .fi-resource-articles .fi-ta-actions-cell .fi-ta-actions {
                    display: grid !important;
                    grid-template-columns: repeat(3, 2rem);
                    gap: 0.25rem 0.5rem;
                    justify-content: start;
                    align-items: center;
                    width: max-content;
                }

                .fi-resource-articles .article-list-table-shell--queue .fi-ta-actions-cell .fi-ta-actions {
                    grid-template-columns: repeat(4, 2rem);
                    gap: 0.375rem 0.625rem;
                }

                .article-seo-modal {
                    position: fixed;
                    inset: 0;
                    z-index: 120;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 1rem;
                }

                .article-seo-modal.is-open {
                    display: flex;
                }

                body.article-seo-modal-open {
                    overflow: hidden;
                }

                .article-seo-modal__backdrop {
                    position: absolute;
                    inset: 0;
                    background: rgb(0 0 0 / 0.5);
                }

                .article-seo-modal__dialog {
                    position: relative;
                    z-index: 1;
                    display: flex;
                    flex-direction: column;
                    width: min(56rem, 100%);
                    max-height: min(90vh, 720px);
                    background: #fff;
                    border-radius: 0.75rem;
                    box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
                    overflow: hidden;
                }

                .dark .article-seo-modal__dialog {
                    background: rgb(17 24 39);
                }

                .article-seo-modal__header {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    gap: 1rem;
                    padding: 1.25rem 1.5rem;
                    border-bottom: 1px solid rgb(229 231 235);
                }

                .dark .article-seo-modal__header {
                    border-color: rgb(55 65 81);
                }

                .article-seo-modal__title {
                    margin: 0;
                    font-size: 1.125rem;
                    font-weight: 600;
                    color: rgb(17 24 39);
                }

                .dark .article-seo-modal__title {
                    color: rgb(243 244 246);
                }

                .article-seo-modal__subtitle {
                    margin: 0.25rem 0 0;
                    font-size: 0.875rem;
                    color: rgb(107 114 128);
                    word-break: break-word;
                }

                .article-seo-modal__icon-close {
                    flex-shrink: 0;
                    display: inline-flex;
                    padding: 0.375rem;
                    border: none;
                    border-radius: 0.5rem;
                    background: transparent;
                    color: rgb(107 114 128);
                    cursor: pointer;
                }

                .article-seo-modal__icon-close:hover {
                    background: rgb(243 244 246);
                    color: rgb(17 24 39);
                }

                .article-seo-modal__body {
                    flex: 1;
                    overflow-y: auto;
                    padding: 1rem 1.5rem;
                    min-height: 12rem;
                }

                .article-seo-modal__loading {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 0.75rem;
                    padding: 3rem 1rem;
                    font-size: 0.875rem;
                    color: rgb(107 114 128);
                }

                .article-seo-modal__loading--hidden {
                    display: none !important;
                }

                .article-seo-modal__error--hidden {
                    display: none !important;
                }

                .article-seo-modal__error {
                    margin: 0;
                    padding: 1rem;
                    border-radius: 0.5rem;
                    background: rgb(254 226 226);
                    color: rgb(185 28 28);
                    font-size: 0.875rem;
                }

                .article-seo-modal__footer {
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: flex-end;
                    gap: 0.75rem;
                    padding: 1rem 1.5rem;
                    border-top: 1px solid rgb(229 231 235);
                }

                .dark .article-seo-modal__footer {
                    border-color: rgb(55 65 81);
                }

                .article-seo-modal__edit--hidden {
                    display: none !important;
                }

                .seo-article-preview-modal-root {
                    max-height: none;
                    overflow: visible;
                }
        </style>
    @endonce
</x-filament-panels::page>
