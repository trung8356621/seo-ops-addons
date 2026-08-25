@php
    use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;

    $scoringFilters = $this->getScoringRuleFilterDefinitions();
    $aggregateFilters = $this->getAggregateFilterDefinitions();
    $selectedSiteId = (int) ($filterSiteId ?? 0);
    $canScan = $selectedSiteId > 0;
    $paginator = $hasScanned ? $this->resultsPaginator : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
    $visibleIds = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    $articleFocusMap = collect($paginator->items())
        ->mapWithKeys(static fn (array $row): array => [
            (int) ($row['id'] ?? 0) => (bool) ($row['has_focus_keyword'] ?? false),
        ])
        ->all();
    $assignOpenEvent = AssignToContentProjectContract::OPEN_EVENT;
    $assignSuccessEvent = AssignToContentProjectContract::SUCCESS_EVENT;
@endphp

@if ($defaultLoading)
    <div
        class="mb-4 space-y-3 animate-pulse"
        wire:loading.class.remove="hidden"
        wire:target="runScan,loadDefaultAuditResults"
    >
        @foreach (range(1, 5) as $skeletonRow)
            <div class="h-12 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
        @endforeach
    </div>
@endif

<div
    class="fi-page articles-optimal-page"
    x-data="{
        selectedArticleIds: @entangle('selectedArticleIds'),
        bulkMenuOpen: false,
        visibleIds: @js($visibleIds),
        articleFocusMap: @js($articleFocusMap),
        removedIds: [],
        assignOpenEvent: @js($assignOpenEvent),
        assignSuccessEvent: @js($assignSuccessEvent),
        hideRows(ids) {
            const nums = (Array.isArray(ids) ? ids : [ids]).map(Number).filter((id) => id > 0);
            this.removedIds = Array.from(new Set([...this.removedIds.map(Number), ...nums]));
            this.selectedArticleIds = this.selectedArticleIds.map(Number).filter((id) => ! this.removedIds.includes(id));
        },
        runSkipRow(articleId) {
            const id = Number(articleId);
            if (id <= 0) {
                return;
            }
            this.hideRows([id]);
            this.$wire.skipSeoAudit(id);
        },
        init() {
            this.$watch('selectedArticleIds', (value) => {
                if (! Array.isArray(value) || value.length === 0) {
                    this.bulkMenuOpen = false;
                }
            });
            window.addEventListener(this.assignSuccessEvent, (event) => {
                const detail = event.detail || {};
                if (detail.source !== 'seo_audit' && detail.source !== 'seo_audit_bulk') {
                    return;
                }
                const ids = Array.isArray(detail.article_ids) ? detail.article_ids : [];
                if (ids.length > 0) {
                    this.hideRows(ids);
                }
            });
        },
        selectableVisibleIds() {
            return this.visibleIds.map(Number);
        },
        assignableSelectedIds() {
            return this.selectedArticleIds
                .map(Number)
                .filter((id) => id > 0);
        },
        hasSelectedMissingKeyword() {
            return this.selectedArticleIds
                .map(Number)
                .some((id) => id > 0 && ! this.articleFocusMap[id]);
        },
        visibleSelected() {
            const selectable = this.selectableVisibleIds();
            return selectable.length > 0 && selectable.every((id) => this.selectedArticleIds.map(Number).includes(Number(id)));
        },
        syncVisibleIds(nextVisibleIds) {
            this.visibleIds = nextVisibleIds.map(Number);
            this.selectedArticleIds = this.selectedArticleIds
                .map(Number)
                .filter((id) => this.visibleIds.includes(id));
        },
        toggleSelectAll(checked) {
            this.selectedArticleIds = checked ? this.selectableVisibleIds() : [];
        },
        openAssignDrawer(articleId = null) {
            this.bulkMenuOpen = false;
            const ids = articleId
                ? [Number(articleId)].filter((id) => id > 0)
                : this.assignableSelectedIds();
            if (ids.length === 0) {
                return;
            }
            const needsFocus = articleId
                ? ! this.articleFocusMap[Number(articleId)]
                : this.hasSelectedMissingKeyword();
            window.dispatchEvent(new CustomEvent(this.assignOpenEvent, {
                detail: {
                    mode: 'article',
                    source: articleId ? 'seo_audit' : 'seo_audit_bulk',
                    article_ids: ids,
                    site_ids: [@js($selectedSiteId)].filter((id) => Number(id) > 0),
                    defaults: { type: 'rewrite' },
                    options: {
                        ignore_monthly_capacity: true,
                        detect_missing_focus_keyword: true,
                        show_focus_keyword: needsFocus,
                        show_quick_create: false,
                        show_article_fields: true,
                        show_keyword_override: false,
                        show_title_override: false,
                    },
                },
            }));
        },
        runSkipSelected() {
            this.bulkMenuOpen = false;
            const ids = this.selectedArticleIds.map(Number);
            if (ids.length === 0) {
                return;
            }
            this.hideRows(ids);
            this.$wire.skipSelectedSeoAudit(ids);
        },
        runAssignSelected() {
            this.bulkMenuOpen = false;
            if (this.assignableSelectedIds().length === 0) {
                return;
            }
            this.openAssignDrawer(null);
        },
    }"
>
    <span
        wire:key="articles-optimal-visible-ids-{{ md5(json_encode([$visibleIds, $articleFocusMap])) }}"
        x-init="articleFocusMap = @js($articleFocusMap); syncVisibleIds(@js($visibleIds))"
        class="hidden"
    ></span>

    <div class="space-y-6">
        <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
            <p class="font-medium">{{ __('seo-content-ai::filament.articles_optimal.content_project_suggestions_notice_title') }}</p>
            <p class="mt-1 text-sky-900/80 dark:text-sky-100/80">
                {{ __('seo-content-ai::filament.articles_optimal.content_project_suggestions_notice_body') }}
            </p>
            <a
                href="{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('index') }}"
                class="mt-2 inline-flex text-sm font-medium text-sky-800 underline hover:text-sky-950 dark:text-sky-200"
            >
                {{ __('seo-content-ai::filament.articles_optimal.content_project_suggestions_notice_link') }}
            </a>
        </div>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.articles_optimal.filters_heading') }}
            </x-slot>

            <form wire:submit="runScan" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.articles_optimal.domain_label') }}
                            <span class="text-rose-600">*</span>
                        </label>
                        <x-select
                            wire:model.live="filterSiteId"
                            required
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.domain_placeholder') }}</option>
                            @foreach ($this->getSiteFilterOptions() as $siteId => $domainLabel)
                                <option value="{{ $siteId }}">{{ $domainLabel }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.articles_optimal.domain_help') }}
                        </p>
                        @if (! $canScan)
                            <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">
                                {{ __('seo-content-ai::filament.articles_optimal.domain_required') }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.articles_optimal.language_label') }}
                        </label>
                        <x-select
                            wire:model.live="filterLanguage"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.language_all') }}</option>
                            @foreach ($this->getLanguageOptions() as $langCode => $langLabel)
                                <option value="{{ $langCode }}">{{ $langLabel }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.articles_optimal.post_type_label') }}
                        </label>
                        <x-select
                            wire:model.live="filterPostType"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.post_type_all') }}</option>
                            @foreach ($this->getPostTypeOptions() as $postType => $postTypeLabel)
                                <option value="{{ $postType }}">{{ $postTypeLabel }}</option>
                            @endforeach
                        </x-select>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_group_scoring_rules') }}
                        @if (count($scoringFilters) > 0)
                            <span class="font-normal text-gray-500">({{ count($scoringFilters) }})</span>
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_scoring_any_hint') }}
                    </p>
                    @if ($scoringFilters === [])
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.articles_optimal.filter_scoring_empty') }}
                        </p>
                    @else
                        <div class="mt-2 grid gap-3 md:grid-cols-2">
                            @foreach ($scoringFilters as $filter)
                                <label wire:key="audit-filter-{{ $filter['key'] }}" class="inline-flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input
                                        type="checkbox"
                                        value="{{ $filter['key'] }}"
                                        wire:model="selectedScoringRuleKeys"
                                        class="mt-0.5 rounded border-gray-300"
                                    >
                                    <span>{{ $filter['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_group_aggregate') }}
                    </p>
                    <div class="mt-2 grid gap-3 md:grid-cols-2">
                        @foreach ($aggregateFilters as $aggregate)
                            <label wire:key="audit-aggregate-{{ $aggregate['key'] }}" class="inline-flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="checkbox"
                                    wire:model="{{ $aggregate['key'] === \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::AGGREGATE_FILTER_LOW_SEO_SCORE ? 'filterLowSeoScore' : 'filterTechnicalSeoScore' }}"
                                    class="mt-0.5 rounded border-gray-300"
                                >
                                <span>{{ $aggregate['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="runScan"
                        :disabled="! $canScan"
                        :class="$canScan ? null : 'opacity-50 pointer-events-none'"
                    >
                        @if ($scanState === 'failed')
                            <span>{{ __('seo-content-ai::filament.articles_optimal.scan_retry') }}</span>
                        @else
                            <span wire:loading.remove wire:target="runScan">
                                {{ __('seo-content-ai::filament.articles_optimal.scan_button') }}
                            </span>
                            <span wire:loading wire:target="runScan">
                                {{ __('seo-content-ai::filament.articles_optimal.scanning') }}
                            </span>
                        @endif
                    </x-filament::button>
                    @if ($scanError)
                        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $scanError }}</p>
                    @endif
                    @if ($scanNotice)
                        <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">{{ $scanNotice }}</p>
                    @endif
                    @if ($scanState === 'empty' && $selectedSiteId > 0)
                        <div class="mt-3">
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                wire:click="queueMissingScoringForFilterSite"
                                wire:loading.attr="disabled"
                                wire:target="queueMissingScoringForFilterSite"
                            >
                                <span wire:loading.remove wire:target="queueMissingScoringForFilterSite">
                                    {{ __('seo-content-ai::filament.articles_optimal.queue_missing_scoring') }}
                                </span>
                                <span wire:loading wire:target="queueMissingScoringForFilterSite">
                                    {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                                </span>
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.articles_optimal.results_heading') }}
            </x-slot>

            @if (! $hasScanned)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.articles_optimal.initial_message') }}
                </p>
            @elseif ($paginator->total() === 0)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.articles_optimal.empty_results') }}
                </p>
            @else
                <div
                    class="mb-3 flex flex-wrap items-center gap-3"
                    x-show="selectedArticleIds.length > 0"
                    x-cloak
                >
                    <div class="relative" x-on:click.outside="bulkMenuOpen = false">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-100 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10"
                            x-on:click="bulkMenuOpen = ! bulkMenuOpen"
                        >
                            <span>{{ __('seo-content-ai::filament.articles_optimal.bulk_actions') }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 shrink-0" />
                        </button>
                        <div
                            x-show="bulkMenuOpen"
                            x-cloak
                            class="absolute left-0 z-20 mt-1 w-64 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-900"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                                x-on:click="runSkipSelected()"
                            >
                                <x-filament::icon icon="heroicon-o-eye-slash" class="h-4 w-4 shrink-0 text-warning-600" />
                                {{ __('seo-content-ai::filament.articles_optimal.action_skip_audit') }}
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                                x-on:click="runAssignSelected()"
                            >
                                <x-filament::icon icon="heroicon-o-folder-plus" class="h-4 w-4 shrink-0 text-warning-600" />
                                {{ \Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label() }}
                            </button>
                        </div>
                    </div>
                    <span class="text-xs text-gray-500" x-text="`${selectedArticleIds.length} {{ __('seo-content-ai::filament.articles_optimal.bulk_selected_suffix') }}`"></span>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <colgroup>
                            <col class="w-10">
                            <col style="width: 22%">
                            <col style="width: 12%">
                            <col>
                            <col class="w-28">
                            <col class="w-36">
                        </colgroup>
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300"
                                        x-bind:checked="visibleSelected()"
                                        x-on:change="toggleSelectAll($event.target.checked)"
                                    >
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_title') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_domain') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_warnings') }}</th>
                                <th class="px-3 py-2 text-left font-semibold whitespace-nowrap">
                                    <button
                                        type="button"
                                        wire:click="sortResultsByScore"
                                        wire:loading.attr="disabled"
                                        wire:target="sortResultsByScore"
                                        @class([
                                            'inline-flex items-center gap-1 text-left hover:text-primary-600 dark:hover:text-primary-400',
                                            'text-primary-600 dark:text-primary-400' => $resultsSortBy === 'score',
                                        ])
                                    >
                                        <span>{{ __('seo-content-ai::filament.articles_optimal.col_score') }}</span>
                                        @if ($resultsSortBy === 'score')
                                            <x-filament::icon
                                                :icon="$resultsSortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
                                                class="h-4 w-4 shrink-0"
                                            />
                                        @else
                                            <x-filament::icon icon="heroicon-m-arrows-up-down" class="h-4 w-4 shrink-0 opacity-50" />
                                        @endif
                                    </button>
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($paginator as $row)
                                <tr wire:key="article-optimal-{{ $row['id'] }}" x-show="! removedIds.map(Number).includes({{ (int) $row['id'] }})">
                                    <td class="px-3 py-3 align-top">
                                        <input
                                            type="checkbox"
                                            value="{{ $row['id'] }}"
                                            class="rounded border-gray-300"
                                            x-bind:checked="selectedArticleIds.map(Number).includes({{ (int) $row['id'] }})"
                                            x-on:change="
                                                const id = {{ (int) $row['id'] }};
                                                selectedArticleIds = $event.target.checked
                                                    ? Array.from(new Set([...selectedArticleIds.map(Number), id]))
                                                    : selectedArticleIds.map(Number).filter((value) => value !== id);
                                            "
                                        >
                                    </td>
                                    <td class="min-w-0 px-3 py-3 align-top">
                                        @if (! empty($row['permalink']))
                                            <a href="{{ $row['permalink'] }}" target="_blank" rel="noopener noreferrer" class="block break-words font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $row['title'] }}
                                            </a>
                                        @else
                                            <span class="block break-words font-medium">{{ $row['title'] }}</span>
                                        @endif
                                    </td>
                                    <td class="min-w-0 px-3 py-3 align-top break-words text-gray-600 dark:text-gray-300">{{ $row['domain'] }}</td>
                                    <td class="min-w-0 px-3 py-3 align-top">
                                        <ul class="list-disc space-y-1 break-words pl-4 text-gray-700 dark:text-gray-300">
                                            @if (! empty($row['has_keyword_flags']))
                                                <li class="font-medium text-amber-700 dark:text-amber-300">
                                                    {{ __('seo-content-ai::filament.articles_optimal.source_keyword_review') }}
                                                    ({{ __('seo-content-ai::filament.articles_optimal.keyword_warning_count', ['count' => (int) ($row['warning_count'] ?? 0)]) }},
                                                    {{ __('seo-content-ai::filament.articles_optimal.keyword_danger_count', ['count' => (int) ($row['danger_count'] ?? 0)]) }})
                                                </li>
                                                @foreach (($row['flagged_keywords'] ?? []) as $flaggedKeyword)
                                                    <li>
                                                        <span class="font-medium">{{ $flaggedKeyword['phrase'] ?? '' }}</span>
                                                        @if (! empty($flaggedKeyword['reason']))
                                                            — {{ $flaggedKeyword['reason'] }}
                                                        @endif
                                                    </li>
                                                @endforeach
                                            @endif
                                            @if (! empty($row['has_seo_rule_matches']))
                                                <li class="font-medium text-sky-700 dark:text-sky-300">{{ __('seo-content-ai::filament.articles_optimal.source_seo_rules') }}</li>
                                                @foreach ($row['reason_labels'] as $label)
                                                    <li>{{ $label }}</li>
                                                @endforeach
                                            @elseif (empty($row['has_keyword_flags']))
                                                @foreach ($row['reason_labels'] as $label)
                                                    <li>{{ $label }}</li>
                                                @endforeach
                                            @endif
                                        </ul>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 align-top">
                                        <span @class([
                                            'font-semibold',
                                            'text-rose-600 dark:text-rose-400' => (int) ($row['score'] ?? 0) < 50,
                                            'text-amber-600 dark:text-amber-400' => (int) ($row['score'] ?? 0) >= 50 && (int) ($row['score'] ?? 0) <= 70,
                                            'text-emerald-600 dark:text-emerald-400' => (int) ($row['score'] ?? 0) > 70,
                                        ])>{{ (int) ($row['score'] ?? 0) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 align-top">
                                        <div class="flex flex-wrap gap-2">
                                            <x-filament::icon-button tag="a" href="{{ $row['edit_url'] }}" icon="heroicon-o-pencil-square" size="sm" color="gray" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_edit') }}" label="{{ __('seo-content-ai::filament.articles_optimal.action_open_article') }}" />
                                            <x-filament::icon-button icon="heroicon-o-eye-slash" size="sm" color="warning" x-on:click="runSkipRow({{ (int) $row['id'] }})" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_skip_audit') }}" />
                                            <x-filament::icon-button
                                                type="button"
                                                icon="{{ \Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::ICON }}"
                                                size="sm"
                                                color="{{ \Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::COLOR }}"
                                                tooltip="{{ \Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label() }}"
                                                label="{{ \Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label() }}"
                                                data-assign-content-project-trigger
                                                x-on:click="openAssignDrawer({{ (int) $row['id'] }})"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $paginator->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>

</div>
