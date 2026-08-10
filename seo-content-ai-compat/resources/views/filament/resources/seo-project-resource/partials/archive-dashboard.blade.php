@php
    use Omnichannel\Addons\Content\Services\ArticleCompletedArchiveQueryService;

    /** @var int $siteId */
    $siteId = (int) ($siteId ?? 0);
    /** @var list<int> $siteIds */
    $siteIds = isset($siteIds) && is_array($siteIds)
        ? array_values(array_map('intval', $siteIds))
        : ($siteId > 0 ? [$siteId] : []);
    $canReopen = (bool) ($canReopen ?? $canUnarchive ?? false);
    $dashboard = app(ArticleCompletedArchiveQueryService::class)->buildGroupedDashboard($siteIds);
    $archiveGroups = $dashboard['groups'];
    $monthOptions = $dashboard['month_options'];
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
        $articles = $group['articles'];
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
        noteModalOpen: false,
        noteModalTitle: '',
        noteModalBody: '',
        historyModalOpen: false,
        historyModalTitle: '',
        historyRows: [],
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
                            const project = (article.project_label || '').toLowerCase();
                            return title.includes(query)
                                || author.includes(query)
                                || domain.includes(query)
                                || project.includes(query);
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
        openNoteModal(article) {
            this.closeMenu();
            this.noteModalTitle = article.title || '';
            this.noteModalBody = article.latest_note || '';
            this.noteModalOpen = true;
        },
        closeNoteModal() {
            this.noteModalOpen = false;
        },
        openHistoryModal(article) {
            this.closeMenu();
            this.historyModalTitle = article.title || '';
            this.historyRows = Array.isArray(article.reviews) ? article.reviews : [];
            this.historyModalOpen = true;
        },
        closeHistoryModal() {
            this.historyModalOpen = false;
        },
        confirmReopen(articleId) {
            this.closeMenu();
            if (! confirm(@js(__('seo-content-ai::filament.projects.unarchive_item_confirm')))) {
                return;
            }
            $wire.reopenArticle(articleId);
        },
    }"
    x-on:keydown.escape.window="
        if (noteModalOpen) { closeNoteModal(); return; }
        if (historyModalOpen) { closeHistoryModal(); return; }
        closeMenu();
    "
>
    <div class="reviewed-dashboard">
        @if ($archiveGroups === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.projects.archive_dashboard_empty') }}
            </p>
        @else
            <div class="reviewed-stats-grid">
                <div class="reviewed-stat-card">
                    <div class="reviewed-stat-card__icon">
                        <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_today') }}</div>
                        <div class="reviewed-stat-card__value" x-text="archiveStatToday()">0</div>
                        <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                    </div>
                </div>
                <div class="reviewed-stat-card">
                    <div class="reviewed-stat-card__icon">
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_week') }}</div>
                        <div class="reviewed-stat-card__value" x-text="archiveStatWeek()">0</div>
                        <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                    </div>
                </div>
                <div class="reviewed-stat-card">
                    <div class="reviewed-stat-card__icon">
                        <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_month') }}</div>
                        <div class="reviewed-stat-card__value" x-text="archiveStatMonth()">0</div>
                        <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                    </div>
                </div>
                <div class="reviewed-stat-card">
                    <div class="reviewed-stat-card__icon">
                        <x-filament::icon icon="heroicon-o-archive-box" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.projects.archive_stat_total') }}</div>
                        <div class="reviewed-stat-card__value" x-text="archiveStatTotal()">0</div>
                        <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                    </div>
                </div>
            </div>

            <div class="reviewed-toolbar">
                <div class="reviewed-toolbar__search">
                    <label class="reviewed-field__label sr-only" for="archive-search-input">{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}</label>
                    <input
                        id="archive-search-input"
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
                            <label class="reviewed-field__label" for="archive-domain-filter">{{ __('seo-content-ai::filament.article_list.domain') }}</label>
                            <x-select id="archive-domain-filter" x-model="archiveDomainFilter" class="reviewed-field__input">
                                <option value="all">{{ __('seo-content-ai::filament.projects.archive_filter_domain_all') }}</option>
                                @foreach ($domainOptions as $domainOption)
                                    <option value="{{ $domainOption['value'] }}">{{ $domainOption['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif
                    <div class="reviewed-field">
                        <label class="reviewed-field__label" for="archive-month-filter">{{ __('seo-content-ai::filament.projects.month') }}</label>
                        <x-select id="archive-month-filter" x-model="archiveMonthFilter" class="reviewed-field__input">
                            <option value="all">{{ __('seo-content-ai::filament.projects.archive_filter_month_all') }}</option>
                            @foreach ($monthOptions as $monthOption)
                                <option value="{{ $monthOption['value'] }}">{{ $monthOption['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="reviewed-field">
                        <label class="reviewed-field__label" for="archive-date-filter">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date') }}</label>
                        <x-select id="archive-date-filter" x-model="archiveDateFilter" class="reviewed-field__input">
                            <option value="all">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_all') }}</option>
                            <option value="today">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_today') }}</option>
                            <option value="week">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_week') }}</option>
                            <option value="month">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_month') }}</option>
                        </x-select>
                    </div>
                    <div class="reviewed-field">
                        <label class="reviewed-field__label" for="archive-sort-filter">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort') }}</label>
                        <x-select id="archive-sort-filter" x-model="archiveSort" class="reviewed-field__input">
                            <option value="newest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_newest') }}</option>
                            <option value="oldest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_oldest') }}</option>
                        </x-select>
                    </div>
                </div>
            </div>

            <p
                x-show="filteredArchiveGroups().length === 0"
                x-cloak
                class="text-sm text-gray-600 dark:text-gray-300"
            >
                {{ __('seo-content-ai::filament.projects.archive_no_matches') }}
            </p>

            <div class="reviewed-day-groups" x-show="filteredArchiveGroups().length > 0">
                <template x-for="group in filteredArchiveGroups()" :key="group.date">
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
                                        x-text="archiveBadgeLabel(group.count)"
                                    ></span>
                                </div>
                            </div>
                            <div class="reviewed-day-card__meta">
                                <div class="reviewed-day-card__meta-item">
                                    <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.projects.archive_first_completed') }}</span>
                                    <span class="reviewed-day-card__meta-value" x-text="group.first_review"></span>
                                </div>
                                <div class="reviewed-day-card__meta-item">
                                    <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.projects.archive_last_completed') }}</span>
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
                                                <span x-text="article.author"></span>
                                                <template x-if="article.domain && article.domain !== '—'">
                                                    <span>
                                                        <span aria-hidden="true">·</span>
                                                        <span x-text="article.domain"></span>
                                                    </span>
                                                </template>
                                                <template x-if="article.project_label">
                                                    <span>
                                                        <span aria-hidden="true">·</span>
                                                        <span x-text="article.project_label"></span>
                                                    </span>
                                                </template>
                                                <span aria-hidden="true">·</span>
                                                <span>{{ __('seo-content-ai::filament.projects.completed_at') }}</span>
                                                <span x-text="article.completed_at_label || article.completed_time"></span>
                                                <template x-if="article.completed_by && article.completed_by !== '—'">
                                                    <span>
                                                        <span aria-hidden="true">·</span>
                                                        <span x-text="article.completed_by"></span>
                                                    </span>
                                                </template>
                                            </div>
                                        </div>
                                        <div class="reviewed-article-item__actions" x-on:click.outside="if (openMenuArticleId === article.id) closeMenu()">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-600 ring-1 ring-gray-300 bg-white shadow-sm transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                                                x-bind:aria-expanded="openMenuArticleId === article.id"
                                                aria-label="{{ __('seo-content-ai::filament.projects.archive_actions_menu') }}"
                                                x-on:click.stop="toggleMenu(article.id)"
                                            >
                                                <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-5 w-5" />
                                            </button>

                                            <div
                                                x-show="openMenuArticleId === article.id"
                                                x-cloak
                                                x-transition
                                                class="reviewed-article-item__actions-menu"
                                            >
                                                <a
                                                    x-bind:href="article.edit_url"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
                                                    x-on:click="closeMenu()"
                                                >
                                                    <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                                    <span>{{ __('seo-content-ai::filament.projects.archive_open_article') }}</span>
                                                </a>
                                                <button
                                                    type="button"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
                                                    x-on:click="openHistoryModal(article)"
                                                >
                                                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                                                    <span>{{ __('seo-content-ai::filament.projects.archive_view_history') }}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-800"
                                                    x-bind:disabled="!article.has_note"
                                                    x-on:click="article.has_note && openNoteModal(article)"
                                                >
                                                    <x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" class="h-4 w-4" />
                                                    <span>{{ __('seo-content-ai::filament.projects.archive_view_note') }}</span>
                                                </button>
                                                @if ($canReopen)
                                                    <button
                                                        type="button"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger-700 hover:bg-danger-50 disabled:opacity-50 dark:text-danger-400 dark:hover:bg-danger-500/10"
                                                        wire:loading.attr="disabled"
                                                        wire:target="reopenArticle"
                                                        x-on:click="confirmReopen(article.id)"
                                                    >
                                                        <x-filament::icon
                                                            icon="heroicon-o-arrow-uturn-left"
                                                            class="h-4 w-4"
                                                            wire:loading.remove
                                                            wire:target="reopenArticle"
                                                        />
                                                        <x-filament::loading-indicator
                                                            class="h-4 w-4"
                                                            wire:loading
                                                            wire:target="reopenArticle"
                                                        />
                                                        <span wire:loading.remove wire:target="reopenArticle">{{ __('seo-content-ai::filament.projects.unarchive_item') }}</span>
                                                        <span wire:loading wire:target="reopenArticle">{{ __('seo-content-ai::filament.projects.unarchive_item_running') }}</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @endif
    </div>

    <template x-teleport="body">
        <div
            x-show="noteModalOpen"
            x-cloak
            class="fixed inset-0 z-[80] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="absolute inset-0 bg-gray-950/50" x-on:click="closeNoteModal()"></div>
            <div class="relative z-10 w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('seo-content-ai::filament.projects.archive_view_note') }}</h3>
                        <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400" x-text="noteModalTitle"></p>
                    </div>
                    <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" x-on:click="closeNoteModal()">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <p class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200" x-text="noteModalBody || '—'"></p>
            </div>
        </div>
    </template>

    <template x-teleport="body">
        <div
            x-show="historyModalOpen"
            x-cloak
            class="fixed inset-0 z-[80] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="absolute inset-0 bg-gray-950/50" x-on:click="closeHistoryModal()"></div>
            <div class="relative z-10 flex max-h-[80vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('seo-content-ai::filament.article_review.modal.history_title') }}</h3>
                        <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400" x-text="historyModalTitle"></p>
                    </div>
                    <button type="button" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" x-on:click="closeHistoryModal()">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="overflow-y-auto px-5 py-4">
                    <p
                        x-show="historyRows.length === 0"
                        class="text-sm text-gray-600 dark:text-gray-300"
                    >{{ __('seo-content-ai::filament.article_review.modal.history_empty') }}</p>
                    <ul class="space-y-3" x-show="historyRows.length > 0">
                        <template x-for="(row, index) in historyRows" :key="index">
                            <li class="rounded-xl bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-gray-800/60 dark:ring-gray-700">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                    <span class="font-semibold text-gray-900 dark:text-white" x-text="row.action_label || row.action"></span>
                                    <span class="text-gray-400" aria-hidden="true">·</span>
                                    <span class="text-gray-600 dark:text-gray-300" x-text="(row.from_status || '—') + ' → ' + (row.to_status || '—')"></span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="row.reviewer"></span>
                                    <span aria-hidden="true">·</span>
                                    <span x-text="row.at"></span>
                                </div>
                                <p
                                    x-show="row.note"
                                    class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200"
                                    x-text="row.note"
                                ></p>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </template>
</div>
