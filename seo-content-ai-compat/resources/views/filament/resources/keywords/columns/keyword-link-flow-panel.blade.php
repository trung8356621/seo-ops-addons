@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    use Omnichannel\Addons\SearchFoundation\Support\KeywordLinkDetailPanelPresenter;

    $presenter = app(KeywordLinkDetailPanelPresenter::class);
    $items = $presenter->buildItems($record);
    $tabCounts = KeywordLinkDetailPanelPresenter::tabCounts($items);
@endphp

@if ($items === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('seo-content-ai::filament.keyword.link_detail_empty') }}
    </p>
@else
    <div
        class="keyword-link-detail-panel space-y-4"
        x-data="{
            tab: 'all',
            counts: @js($tabCounts),
            items: @js($items),
            matchesTab(item) {
                if (this.tab === 'broken') {
                    return Boolean(item.is_broken_network);
                }

                if (this.tab === 'weak_context') {
                    return Boolean(item.weak_context);
                }

                return true;
            },
            visibleItems() {
                return this.items.filter((item) => this.matchesTab(item));
            },
        }"
    >
        <div class="keyword-link-detail-tabs" role="tablist" aria-label="{{ __('seo-content-ai::filament.keyword.link_detail_filter_heading') }}">
            @foreach ([
                'all' => __('seo-content-ai::filament.keyword.link_detail_tab_all'),
                'broken' => __('seo-content-ai::filament.keyword.link_detail_tab_broken'),
                'weak_context' => __('seo-content-ai::filament.keyword.link_detail_tab_weak_context'),
            ] as $tabKey => $tabLabel)
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === '{{ $tabKey }}'"
                    @click="tab = '{{ $tabKey }}'"
                    :class="tab === '{{ $tabKey }}' ? 'keyword-link-detail-tab is-active' : 'keyword-link-detail-tab'"
                >
                    <span>{{ $tabLabel }}</span>
                    <span class="keyword-link-detail-tab__badge" x-text="counts['{{ $tabKey }}'] ?? 0"></span>
                </button>
            @endforeach
        </div>

        <div class="space-y-3">
            <template x-for="item in visibleItems()" :key="item.id">
                <article
                    class="keyword-link-detail-card overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-gray-900/50"
                    :class="item.network?.card_border_class ?? 'border-gray-200 dark:border-white/10'"
                >
                    <header class="flex flex-wrap items-start gap-3 border-b border-gray-100 px-3 py-3 dark:border-white/10">
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold uppercase text-slate-600 dark:bg-white/10 dark:text-slate-200"
                            x-text="item.domain_initials ?? '?'"
                        ></span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="item.source_title ?? '—'"></p>
                            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400" x-text="item.domain ?? '—'"></p>
                        </div>

                        <span
                            class="shrink-0"
                            :class="item.link_type_badge_class"
                            x-text="item.link_type_label"
                        ></span>
                    </header>

                    <div class="px-3 py-3">
                        <div class="keyword-link-detail-context rounded-lg bg-slate-50/70 px-3 py-2.5 dark:bg-white/5">
                            <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-100">
                                <template x-if="item.context_before">
                                    <span class="text-sm italic text-slate-400" x-text="item.context_before + ' '"></span>
                                </template>
                                <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="item.anchor_text"></span>
                                <template x-if="item.context_after">
                                    <span class="text-sm italic text-slate-400" x-text="' ' + item.context_after"></span>
                                </template>
                            </p>
                        </div>
                    </div>

                    <footer class="space-y-3 border-t border-gray-100 px-3 py-3 dark:border-white/10">
                        <div class="flex min-w-0 items-center gap-2">
                            <template x-if="item.network?.show_green_dot">
                                <span class="inline-flex h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                            </template>
                            <a
                                :href="item.target_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-w-0 truncate font-mono text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-300"
                                x-text="item.target_label ?? '—'"
                            ></a>
                            <span
                                class="shrink-0"
                                :class="item.network?.badge_class"
                                x-text="item.network?.label"
                            ></span>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <template x-if="item.source_edit_url">
                                <a
                                    :href="item.source_edit_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                >
                                    {{ __('seo-content-ai::filament.keyword.workspace_edit_article') }}
                                </a>
                            </template>

                            <template x-if="item.can_assign_content_project">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                    :data-assign-link-map="item.id"
                                >
                                    {{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}
                                </button>
                            </template>
                        </div>
                    </footer>
                </article>
            </template>

            <p
                x-show="visibleItems().length === 0"
                x-cloak
                class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400"
            >
                {{ __('seo-content-ai::filament.keyword.link_detail_tab_empty') }}
            </p>
        </div>
    </div>
@endif
