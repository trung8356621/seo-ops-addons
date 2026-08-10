@php
    $reviewedGroups = $this->getReviewedArticlesGrouped();
    $defaultExpandedDate = $reviewedGroups[0]['date'] ?? null;
    $reviewedToday = now()->toDateString();
    $reviewedUiContext = [
        'today' => $reviewedToday,
        'weekStart' => now()->startOfWeek()->toDateString(),
        'weekEnd' => now()->endOfWeek()->toDateString(),
        'monthStart' => now()->startOfMonth()->toDateString(),
        'monthEnd' => now()->endOfMonth()->toDateString(),
    ];

    $reviewedGroupsEnriched = [];
    foreach ($reviewedGroups as $group) {
        $articles = $group['articles'];
        $articleCount = count($articles);

        $reviewedGroupsEnriched[] = array_merge($group, [
            'first_review' => $articleCount > 0 ? (string) ($articles[$articleCount - 1]['reviewed_time'] ?? '—') : '—',
            'last_review' => $articleCount > 0 ? (string) ($articles[0]['reviewed_time'] ?? '—') : '—',
            'is_today' => ($group['date'] ?? '') === $reviewedToday,
        ]);
    }

    $reviewedTabCss = base_path('addons/seo/resources/css/articles-reviewed-tab.css');
@endphp

@if (is_readable($reviewedTabCss))
    <style>{!! file_get_contents($reviewedTabCss) !!}</style>
@endif

<div
    x-data="{
        reviewedUiContext: @js($reviewedUiContext),
        reviewedGroups: @js($reviewedGroupsEnriched),
        reviewedSearch: '',
        reviewedDateFilter: 'all',
        reviewedStatus: 'reviewed',
        reviewedSort: 'newest',
        reviewedBadgeTemplate: @js(__('seo-content-ai::filament.articles_optimal.reviewed_badge_articles', ['count' => ':count'])),
        expandedDates: @js($defaultExpandedDate ? [$defaultExpandedDate] : []),
        reviewedBadgeLabel(count) {
            return this.reviewedBadgeTemplate.replace(':count', String(count));
        },
        countReviewedInRange(start, end) {
            return this.reviewedGroups.reduce((sum, group) => {
                if (group.date >= start && group.date <= end) {
                    return sum + group.count;
                }
                return sum;
            }, 0);
        },
        reviewedStatToday() {
            const { today } = this.reviewedUiContext;
            return this.countReviewedInRange(today, today);
        },
        reviewedStatWeek() {
            const { weekStart, weekEnd } = this.reviewedUiContext;
            return this.countReviewedInRange(weekStart, weekEnd);
        },
        reviewedStatMonth() {
            const { monthStart, monthEnd } = this.reviewedUiContext;
            return this.countReviewedInRange(monthStart, monthEnd);
        },
        reviewedStatTotal() {
            return this.reviewedGroups.reduce((sum, group) => sum + group.count, 0);
        },
        filteredReviewedGroups() {
            const ctx = this.reviewedUiContext;
            let groups = this.reviewedGroups.map((group) => ({
                ...group,
                articles: [...group.articles],
            }));

            if (this.reviewedDateFilter === 'today') {
                groups = groups.filter((group) => group.date === ctx.today);
            } else if (this.reviewedDateFilter === 'week') {
                groups = groups.filter((group) => group.date >= ctx.weekStart && group.date <= ctx.weekEnd);
            } else if (this.reviewedDateFilter === 'month') {
                groups = groups.filter((group) => group.date >= ctx.monthStart && group.date <= ctx.monthEnd);
            }

            const query = this.reviewedSearch.trim().toLowerCase();
            if (query !== '') {
                groups = groups
                    .map((group) => {
                        const articles = group.articles.filter((article) => (article.title || '').toLowerCase().includes(query));
                        return { ...group, articles, count: articles.length };
                    })
                    .filter((group) => group.count > 0);
            }

            groups.sort((left, right) => {
                if (this.reviewedSort === 'oldest') {
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
    }"
>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('seo-content-ai::filament.articles_optimal.reviewed_heading') }}
        </x-slot>

        @if ($reviewedGroups === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.articles_optimal.reviewed_empty') }}
            </p>
        @else
            <div class="reviewed-dashboard">
                <div class="reviewed-stats-grid">
                    <div class="reviewed-stat-card">
                        <div class="reviewed-stat-card__icon">
                            <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_today') }}</div>
                            <div class="reviewed-stat-card__value" x-text="reviewedStatToday()">0</div>
                            <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                        </div>
                    </div>
                    <div class="reviewed-stat-card">
                        <div class="reviewed-stat-card__icon">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_week') }}</div>
                            <div class="reviewed-stat-card__value" x-text="reviewedStatWeek()">0</div>
                            <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                        </div>
                    </div>
                    <div class="reviewed-stat-card">
                        <div class="reviewed-stat-card__icon">
                            <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_month') }}</div>
                            <div class="reviewed-stat-card__value" x-text="reviewedStatMonth()">0</div>
                            <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                        </div>
                    </div>
                    <div class="reviewed-stat-card">
                        <div class="reviewed-stat-card__icon">
                            <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                        </div>
                        <div>
                            <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_total') }}</div>
                            <div class="reviewed-stat-card__value" x-text="reviewedStatTotal()">0</div>
                            <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                        </div>
                    </div>
                </div>

                <div class="reviewed-toolbar">
                    <div class="reviewed-toolbar__search">
                        <label class="reviewed-field__label sr-only" for="reviewed-search-input-articles-list">{{ __('seo-content-ai::filament.articles_optimal.reviewed_search_placeholder') }}</label>
                        <input
                            id="reviewed-search-input-articles-list"
                            type="search"
                            x-model="reviewedSearch"
                            class="reviewed-field__input"
                            placeholder="{{ __('seo-content-ai::filament.articles_optimal.reviewed_search_placeholder') }}"
                            autocomplete="off"
                        >
                    </div>
                    <div class="reviewed-toolbar__filters">
                        <div class="reviewed-field">
                            <label class="reviewed-field__label" for="reviewed-date-filter-articles-list">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date') }}</label>
                            <x-select id="reviewed-date-filter-articles-list" x-model="reviewedDateFilter" class="reviewed-field__input">
                                <option value="all">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_all') }}</option>
                                <option value="today">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_today') }}</option>
                                <option value="week">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_week') }}</option>
                                <option value="month">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_month') }}</option>
                            </x-select>
                        </div>
                        <div class="reviewed-field">
                            <label class="reviewed-field__label" for="reviewed-status-filter-articles-list">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_status') }}</label>
                            <x-select id="reviewed-status-filter-articles-list" x-model="reviewedStatus" class="reviewed-field__input">
                                <option value="reviewed">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_status_reviewed') }}</option>
                            </x-select>
                        </div>
                        <div class="reviewed-field">
                            <label class="reviewed-field__label" for="reviewed-sort-filter-articles-list">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort') }}</label>
                            <x-select id="reviewed-sort-filter-articles-list" x-model="reviewedSort" class="reviewed-field__input">
                                <option value="newest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_newest') }}</option>
                                <option value="oldest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_oldest') }}</option>
                            </x-select>
                        </div>
                    </div>
                </div>

                <p
                    x-show="filteredReviewedGroups().length === 0"
                    x-cloak
                    class="text-sm text-gray-600 dark:text-gray-300"
                >
                    {{ __('seo-content-ai::filament.articles_optimal.reviewed_no_matches') }}
                </p>

                <div class="reviewed-day-groups" x-show="filteredReviewedGroups().length > 0">
                    <template x-for="group in filteredReviewedGroups()" :key="group.date">
                        <div class="reviewed-day-card">
                            <button
                                type="button"
                                class="reviewed-day-card__trigger"
                                x-on:click="toggleDate(group.date)"
                                x-bind:aria-expanded="isDateExpanded(group.date)"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="reviewed-day-card__title-row">
                                        <span class="reviewed-day-card__date" x-text="group.date_label"></span>
                                        <span
                                            x-show="group.is_today"
                                            class="reviewed-day-card__today"
                                        >{{ __('seo-content-ai::filament.articles_optimal.reviewed_today_suffix') }}</span>
                                        <span
                                            class="reviewed-day-card__badge"
                                            x-text="reviewedBadgeLabel(group.count)"
                                        ></span>
                                    </div>
                                </div>
                                <div class="reviewed-day-card__meta">
                                    <div class="reviewed-day-card__meta-item">
                                        <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_first_review') }}</span>
                                        <span class="reviewed-day-card__meta-value" x-text="group.first_review"></span>
                                    </div>
                                    <div class="reviewed-day-card__meta-item">
                                        <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_last_review') }}</span>
                                        <span class="reviewed-day-card__meta-value" x-text="group.last_review"></span>
                                    </div>
                                </div>
                                <svg
                                    class="reviewed-day-card__chevron"
                                    x-bind:class="isDateExpanded(group.date) ? 'is-open' : ''"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div
                                x-show="isDateExpanded(group.date)"
                                x-collapse
                                class="reviewed-day-card__body"
                            >
                                <div class="reviewed-article-list">
                                    <template x-for="article in group.articles" :key="article.id">
                                        <div class="reviewed-article-item">
                                            <div class="reviewed-article-item__icon">
                                                <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                                            </div>
                                            <div class="reviewed-article-item__content">
                                                <div class="reviewed-article-item__title" x-text="article.title"></div>
                                                <div class="reviewed-article-item__meta">
                                                    <span class="reviewed-article-item__status-dot" aria-hidden="true"></span>
                                                    <span>{{ __('seo-content-ai::filament.articles_optimal.reviewed_status_label') }}</span>
                                                    <span aria-hidden="true">·</span>
                                                    <span x-text="article.reviewed_time"></span>
                                                </div>
                                            </div>
                                            <div class="reviewed-article-item__actions">
                                                <a
                                                    x-bind:href="article.view_url || '#'"
                                                    x-bind:aria-disabled="!article.view_url"
                                                    x-bind:class="!article.view_url ? 'pointer-events-none opacity-50' : ''"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 bg-white ring-1 ring-gray-300 shadow-sm transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                                                >
                                                    <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                                    <span>{{ __('seo-content-ai::filament.articles_optimal.reviewed_action_view') }}</span>
                                                </a>
                                                <a
                                                    x-bind:href="article.edit_url"
                                                    class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-primary-600 shadow-sm transition hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                                                >
                                                    <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                                    <span>{{ __('seo-content-ai::filament.articles_optimal.action_edit') }}</span>
                                                </a>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        @endif
    </x-filament::section>
</div>
