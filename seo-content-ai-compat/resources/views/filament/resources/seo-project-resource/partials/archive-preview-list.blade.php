@php
    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($rows ?? null) ? $rows : [];
    /** @var array{groups?: list<array<string, mixed>>, month_options?: list<array{value: string, label: string}>, domain_options?: list<array{value: int, label: string}>} $dashboard */
    $dashboard = is_array($dashboard ?? null) ? $dashboard : [];
    $archiveGroups = $dashboard['groups'] ?? [];
    $monthOptions = $dashboard['month_options'] ?? [];
    $domainOptions = $dashboard['domain_options'] ?? [];
    $defaultExpandedDate = $archiveGroups[0]['date'] ?? null;
    $archiveToday = now()->toDateString();
    $showDomainFilter = count($domainOptions) > 1;

    $archiveUiContext = [
        'today' => $archiveToday,
        'weekStart' => now()->startOfWeek()->toDateString(),
        'weekEnd' => now()->endOfWeek()->toDateString(),
        'monthStart' => now()->startOfMonth()->toDateString(),
        'monthEnd' => now()->endOfMonth()->toDateString(),
    ];

    $archiveGroupsEnriched = [];
    foreach ($archiveGroups as $group) {
        $articles = $group['articles'] ?? [];
        $articleCount = count($articles);
        $archiveGroupsEnriched[] = array_merge($group, [
            'first_review' => $articleCount > 0 ? (string) ($articles[$articleCount - 1]['completed_time'] ?? '—') : '—',
            'last_review' => $articleCount > 0 ? (string) ($articles[0]['completed_time'] ?? '—') : '—',
            'is_today' => ($group['date'] ?? '') === $archiveToday,
        ]);
    }

    $reviewedTabCss = base_path('addons/seo/resources/css/articles-reviewed-tab.css');
@endphp

@if (is_readable($reviewedTabCss))
    <style>{!! file_get_contents($reviewedTabCss) !!}</style>
@endif

<div
    class="reviewed-tab-shell space-y-4"
    x-data="{
        archiveUiContext: @js($archiveUiContext),
        archiveGroups: @js($archiveGroupsEnriched),
        archiveSearch: '',
        archiveMonthFilter: 'all',
        archiveDateFilter: 'all',
        archiveDomainFilter: 'all',
        archiveSort: 'newest',
        archiveBadgeTemplate: @js(__('seo-content-ai::filament.projects.archive_badge_articles', ['count' => ':count'])),
        expandedDates: @js($defaultExpandedDate ? [$defaultExpandedDate] : []),
        openMenuArticleId: null,
        archiveBadgeLabel(count) {
            return this.archiveBadgeTemplate.replace(':count', String(count));
        },
        countArchiveInRange(start, end) {
            return this.archiveGroups.reduce((sum, group) => {
                if (group.date >= start && group.date <= end) {
                    return sum + group.count;
                }
                return sum;
            }, 0);
        },
        archiveStatToday() {
            const { today } = this.archiveUiContext;
            return this.countArchiveInRange(today, today);
        },
        archiveStatWeek() {
            const { weekStart, weekEnd } = this.archiveUiContext;
            return this.countArchiveInRange(weekStart, weekEnd);
        },
        archiveStatMonth() {
            const { monthStart, monthEnd } = this.archiveUiContext;
            return this.countArchiveInRange(monthStart, monthEnd);
        },
        archiveStatTotal() {
            return this.archiveGroups.reduce((sum, group) => sum + group.count, 0);
        },
        filteredArchiveGroups() {
            const ctx = this.archiveUiContext;
            let groups = this.archiveGroups.map((group) => ({
                ...group,
                articles: [...group.articles],
            }));

            if (this.archiveMonthFilter !== 'all') {
                groups = groups.filter((group) => group.month_key === this.archiveMonthFilter);
            }

            if (this.archiveDateFilter === 'today') {
                groups = groups.filter((group) => group.date === ctx.today);
            } else if (this.archiveDateFilter === 'week') {
                groups = groups.filter((group) => group.date >= ctx.weekStart && group.date <= ctx.weekEnd);
            } else if (this.archiveDateFilter === 'month') {
                groups = groups.filter((group) => group.date >= ctx.monthStart && group.date <= ctx.monthEnd);
            }

            if (this.archiveDomainFilter !== 'all') {
                const domainId = Number(this.archiveDomainFilter);
                groups = groups
                    .map((group) => {
                        const articles = group.articles.filter((article) => Number(article.site_id) === domainId);
                        return { ...group, articles, count: articles.length };
                    })
                    .filter((group) => group.count > 0);
            }

            const query = this.archiveSearch.trim().toLowerCase();
            if (query !== '') {
                groups = groups
                    .map((group) => {
                        const articles = group.articles.filter((article) => {
                            const title = (article.title || '').toLowerCase();
                            const author = (article.author || '').toLowerCase();
                            const domain = (article.domain || '').toLowerCase();
                            const keyword = (article.keyword || '').toLowerCase();
                            return title.includes(query)
                                || author.includes(query)
                                || domain.includes(query)
                                || keyword.includes(query);
                        });
                        return { ...group, articles, count: articles.length };
                    })
                    .filter((group) => group.count > 0);
            }

            groups.sort((left, right) => {
                if (this.archiveSort === 'oldest') {
                    return left.date.localeCompare(right.date);
                }
                return right.date.localeCompare(left.date);
            });

            return groups;
        },
        toggleDate(dateKey) {
            if (this.expandedDates.includes(dateKey)) {
                this.expandedDates = this.expandedDates.filter((value) => value !== dateKey);
                return;
            }
            this.expandedDates = [...this.expandedDates, dateKey];
        },
        isDateExpanded(dateKey) {
            return this.expandedDates.includes(dateKey);
        },
        toggleMenu(articleId) {
            this.openMenuArticleId = this.openMenuArticleId === articleId ? null : articleId;
        },
        closeMenu() {
            this.openMenuArticleId = null;
        },
    }"
>
    <div class="reviewed-stats-grid">
        <div class="reviewed-stat-card">
            <div class="reviewed-stat-card__icon">
                <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" />
            </div>
            <div>
                <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_today') }}</div>
                <div class="reviewed-stat-card__value" x-text="archiveStatToday()">0</div>
            </div>
        </div>
        <div class="reviewed-stat-card">
            <div class="reviewed-stat-card__icon">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
            </div>
            <div>
                <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_week') }}</div>
                <div class="reviewed-stat-card__value" x-text="archiveStatWeek()">0</div>
            </div>
        </div>
        <div class="reviewed-stat-card">
            <div class="reviewed-stat-card__icon">
                <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
            </div>
            <div>
                <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_month') }}</div>
                <div class="reviewed-stat-card__value" x-text="archiveStatMonth()">0</div>
            </div>
        </div>
        <div class="reviewed-stat-card">
            <div class="reviewed-stat-card__icon">
                <x-filament::icon icon="heroicon-o-archive-box" class="h-5 w-5" />
            </div>
            <div>
                <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.projects.archive_stat_total') }}</div>
                <div class="reviewed-stat-card__value" x-text="archiveStatTotal()">0</div>
            </div>
        </div>
    </div>

    <div class="reviewed-toolbar">
        <div class="reviewed-toolbar__search">
            <input
                type="search"
                x-model="archiveSearch"
                class="reviewed-field__input"
                placeholder="{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}"
                autocomplete="off"
            >
        </div>
        <div class="reviewed-toolbar__filters">
            @if ($showDomainFilter)
                <div class="reviewed-field">
                    <label class="reviewed-field__label">{{ __('seo-content-ai::filament.article_list.domain') }}</label>
                    <x-select x-model="archiveDomainFilter" class="reviewed-field__input">
                        <option value="all">{{ __('seo-content-ai::filament.projects.archive_filter_domain_all') }}</option>
                        @foreach ($domainOptions as $domainOption)
                            <option value="{{ $domainOption['value'] }}">{{ $domainOption['label'] }}</option>
                        @endforeach
                    </x-select>
                </div>
            @endif
            <div class="reviewed-field">
                <label class="reviewed-field__label">{{ __('seo-content-ai::filament.projects.month') }}</label>
                <x-select x-model="archiveMonthFilter" class="reviewed-field__input">
                    <option value="all">{{ __('seo-content-ai::filament.projects.archive_filter_month_all') }}</option>
                    @foreach ($monthOptions as $monthOption)
                        <option value="{{ $monthOption['value'] }}">{{ $monthOption['label'] }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="reviewed-field">
                <label class="reviewed-field__label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date') }}</label>
                <x-select x-model="archiveDateFilter" class="reviewed-field__input">
                    <option value="all">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_all') }}</option>
                    <option value="today">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_today') }}</option>
                    <option value="week">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_week') }}</option>
                    <option value="month">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_month') }}</option>
                </x-select>
            </div>
            <div class="reviewed-field">
                <label class="reviewed-field__label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort') }}</label>
                <x-select x-model="archiveSort" class="reviewed-field__input">
                    <option value="newest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_newest') }}</option>
                    <option value="oldest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_oldest') }}</option>
                </x-select>
            </div>
        </div>
    </div>

    <p x-show="filteredArchiveGroups().length === 0" x-cloak class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('seo-content-ai::filament.projects.archive_no_matches') }}
    </p>

    <div class="reviewed-day-groups" x-show="filteredArchiveGroups().length > 0">
        <template x-for="group in filteredArchiveGroups()" :key="group.date">
            <div class="reviewed-day-card">
                <button type="button" class="reviewed-day-card__trigger" x-on:click="toggleDate(group.date)">
                    <div class="min-w-0 flex-1">
                        <div class="reviewed-day-card__title-row">
                            <span class="reviewed-day-card__date" x-text="group.date_label"></span>
                            <span class="reviewed-day-card__badge" x-text="archiveBadgeLabel(group.count)"></span>
                        </div>
                    </div>
                </button>
                <div x-show="isDateExpanded(group.date)" class="reviewed-day-card__body">
                    <div class="reviewed-article-list">
                        <template x-for="article in group.articles" :key="article.item_id || article.id">
                            <div class="reviewed-article-item">
                                <div class="reviewed-article-item__content">
                                    <div class="reviewed-article-item__title" x-text="article.title"></div>
                                    <div class="reviewed-article-item__meta">
                                        <span x-text="article.author"></span>
                                        <template x-if="article.domain && article.domain !== '—'">
                                            <span>
                                                <span aria-hidden="true">·</span>
                                                <span x-text="article.domain"></span>
                                            </span>
                                        </template>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('seo-content-ai::filament.projects.completed_at') }}</span>
                                        <span x-text="article.completed_at_label"></span>
                                    </div>
                                </div>
                                <div class="reviewed-article-item__actions" x-on:click.outside="if (openMenuArticleId === article.id) closeMenu()">
                                    <button
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-600 ring-1 ring-gray-300 bg-white shadow-sm"
                                        x-on:click.stop="toggleMenu(article.id)"
                                    >
                                        <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-5 w-5" />
                                    </button>
                                    <div x-show="openMenuArticleId === article.id" x-cloak class="reviewed-article-item__actions-menu">
                                        <template x-if="article.edit_url">
                                            <a x-bind:href="article.edit_url" class="flex w-full items-center gap-2 px-3 py-2 text-sm" x-on:click="closeMenu()">
                                                {{ __('seo-content-ai::filament.projects.archive_open_article') }}
                                            </a>
                                        </template>
                                        <template x-if="article.view_url">
                                            <a x-bind:href="article.view_url" target="_blank" rel="noopener" class="flex w-full items-center gap-2 px-3 py-2 text-sm" x-on:click="closeMenu()">
                                                {{ __('seo-content-ai::filament.projects.archive_preview_open_wp') }}
                                            </a>
                                        </template>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
                                            x-on:click="
                                                closeMenu();
                                                $wire.mountAction('viewArchiveItem', { itemId: article.item_id });
                                            "
                                        >
                                            {{ __('seo-content-ai::filament.projects.archive_preview_item') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
                                            x-on:click="
                                                closeMenu();
                                                $wire.mountAction('linkShare', { itemId: article.item_id, articleId: article.id });
                                            "
                                        >
                                            {{ __('seo-content-ai::filament.projects.archive_preview_col_social') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
