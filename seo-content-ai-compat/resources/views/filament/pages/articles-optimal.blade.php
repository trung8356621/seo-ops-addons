@php
    $projectOptions = $this->getContentProjectOptions();
    $contentProjectsUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('index');
    $assignTypeOptions = $this->getAssignTypeOptions();
    $rewriteModeOptions = $this->getRewriteModeOptions();
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
        sidebarProjectId: @entangle('sidebarProjectId'),
        selectedArticleIds: @entangle('selectedArticleIds'),
        sidebarCollapsed: @entangle('sidebarCollapsed'),
        bulkMenuOpen: false,
        assignArticleId: null,
        assignType: 'rewrite',
        rewriteMode: 'keyword',
        rewriteNotes: '',
        assignFocusKeyword: '',
        assignNeedsKeyword: false,
        visibleIds: @js($visibleIds),
        articleFocusMap: @js($articleFocusMap),
        removedIds: [],
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
        },
        selectableVisibleIds() {
            return this.visibleIds.map(Number);
        },
        assignableSelectedIds() {
            return this.selectedArticleIds
                .map(Number)
                .filter((id) => id > 0 && !! this.articleFocusMap[id]);
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
        openAssignSidebar(articleId = null) {
            this.bulkMenuOpen = false;
            this.assignArticleId = articleId ? Number(articleId) : null;
            this.assignType = 'rewrite';
            this.rewriteMode = 'keyword';
            this.rewriteNotes = '';
            this.assignFocusKeyword = '';
            this.assignNeedsKeyword = this.assignArticleId
                ? ! this.articleFocusMap[this.assignArticleId]
                : false;
            this.sidebarCollapsed = false;
        },
        closeAssignSidebar() {
            this.sidebarCollapsed = true;
            this.assignArticleId = null;
            this.assignNeedsKeyword = false;
            this.assignFocusKeyword = '';
        },
        clearAssignTarget() {
            this.assignArticleId = null;
            this.assignNeedsKeyword = false;
            this.assignFocusKeyword = '';
        },
        assignTargetIds() {
            if (this.assignArticleId) {
                return [Number(this.assignArticleId)];
            }

            return this.assignableSelectedIds();
        },
        canSubmitAssign() {
            return !! this.sidebarProjectId
                && this.assignTargetIds().length > 0
                && (! this.assignNeedsKeyword || String(this.assignFocusKeyword || '').trim() !== '');
        },
        submitSidebarAssign() {
            const ids = this.assignTargetIds();
            if (ids.length === 0 || ! this.sidebarProjectId) {
                return;
            }

            const focusKeyword = String(this.assignFocusKeyword || '').trim();
            if (this.assignNeedsKeyword && focusKeyword === '') {
                return;
            }

            const payload = {
                project_id: this.sidebarProjectId,
                type: this.assignType,
                rewrite_mode: 'content',
                rewrite_notes: this.rewriteNotes,
                focus_keyword: focusKeyword,
            };

            this.hideRows(ids);
            this.clearAssignTarget();
            this.$wire.assignFromSidebar(ids, payload);
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
            if (this.hasSelectedMissingKeyword()) {
                this.$wire.notifyAssignBlockedMissingKeyword();

                return;
            }
            if (this.assignableSelectedIds().length === 0) {
                return;
            }
            this.openAssignSidebar(null);
        },
    }"
>
    <span
        wire:key="articles-optimal-visible-ids-{{ md5(json_encode([$visibleIds, $articleFocusMap])) }}"
        x-init="articleFocusMap = @js($articleFocusMap); syncVisibleIds(@js($visibleIds))"
        class="hidden"
    ></span>

    <div
        class="space-y-6 transition-all duration-300"
        x-bind:style="! sidebarCollapsed ? 'padding-right: 31%;' : 'padding-right: 0;'"
    >
        <div class="space-y-6">
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
                        @disabled(! $canScan)
                        @class(['opacity-50 pointer-events-none' => ! $canScan])
                    >
                        @if ($scanState === 'failed')
                            <span>{{ __('seo-content-ai::filament.articles_optimal.scan_retry') }}</span>
                        @else
                            <span wire:loading.remove wire:target="runScan">
                                {{ __('seo-content-ai::filament.articles_optimal.scan_button') }}
                            </span>
                            <span wire:loading wire:target="runScan" class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
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
                                <span wire:loading wire:target="queueMissingScoringForFilterSite" class="inline-flex items-center gap-2">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
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
                    <div class="relative" @click.outside="bulkMenuOpen = false">
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
                                x-bind:title="hasSelectedMissingKeyword() ? @js(__('seo-content-ai::filament.articles_optimal.assign_missing_keyword_bulk')) : ''"
                            >
                                <x-filament::icon icon="heroicon-o-folder-plus" class="h-4 w-4 shrink-0 text-warning-600" />
                                {{ __('seo-content-ai::filament.articles_optimal.action_assign_project') }}
                            </button>
                        </div>
                    </div>
                    <span class="text-xs text-gray-500" x-text="`${selectedArticleIds.length} {{ __('seo-content-ai::filament.articles_optimal.bulk_selected_suffix') }}`"></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="w-10 px-3 py-2 text-left font-semibold">
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
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_score') }}</th>
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
                                    <td class="px-3 py-3 align-top">
                                        @if (! empty($row['permalink']))
                                            <a href="{{ $row['permalink'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $row['title'] }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $row['title'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">{{ $row['domain'] }}</td>
                                    <td class="px-3 py-3 align-top">
                                        <ul class="list-disc pl-4 space-y-1 text-gray-700 dark:text-gray-300">
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
                                    <td class="px-3 py-3 align-top">
                                        <span @class([
                                            'font-semibold',
                                            'text-rose-600 dark:text-rose-400' => (int) ($row['score'] ?? 0) < 50,
                                            'text-amber-600 dark:text-amber-400' => (int) ($row['score'] ?? 0) >= 50 && (int) ($row['score'] ?? 0) <= 70,
                                            'text-emerald-600 dark:text-emerald-400' => (int) ($row['score'] ?? 0) > 70,
                                        ])>{{ (int) ($row['score'] ?? 0) }}</span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="flex flex-wrap gap-2">
                                            <x-filament::icon-button tag="a" href="{{ $row['edit_url'] }}" icon="heroicon-o-pencil-square" size="sm" color="gray" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_edit') }}" label="{{ __('seo-content-ai::filament.articles_optimal.action_open_article') }}" />
                                            <x-filament::icon-button icon="heroicon-o-eye-slash" size="sm" color="warning" x-on:click="runSkipRow({{ (int) $row['id'] }})" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_skip_audit') }}" />
                                            <x-filament::icon-button
                                                icon="heroicon-o-folder-plus"
                                                size="sm"
                                                color="info"
                                                x-on:click="openAssignSidebar({{ (int) $row['id'] }})"
                                                tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_assign_project') }}"
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

    <aside
        class="overflow-y-auto border-l border-gray-200 bg-white p-4 shadow-xl transition-transform duration-300 dark:border-white/10 dark:bg-gray-900"
        style="position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 50;"
        x-bind:style="sidebarCollapsed
            ? 'position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 50; transform: translateX(100%); pointer-events: none;'
            : 'position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 50; transform: translateX(0); pointer-events: auto;'"
        x-bind:aria-hidden="sidebarCollapsed"
    >
        <div class="mt-20 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.articles_optimal.assign_modal_heading') }}
                </h3>
                <x-filament::icon-button
                    type="button"
                    icon="heroicon-o-x-mark"
                    color="gray"
                    x-on:click="closeAssignSidebar()"
                    tooltip="{{ __('seo-content-ai::filament.articles_optimal.sidebar_collapse') }}"
                />
            </div>

            <p class="text-xs text-gray-500" x-text="`${assignTargetIds().length} {{ __('seo-content-ai::filament.articles_optimal.bulk_selected_suffix') }}`"></p>

            <div class="flex items-end gap-2">
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.articles_optimal.sidebar_project_label') }}</label>
                    <x-select
                        x-ref="projectSelect"
                        x-model="sidebarProjectId"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                    >
                        <option value="">{{ __('seo-content-ai::filament.articles_optimal.sidebar_select_project') }}</option>
                        @foreach ($projectOptions as $projectId => $projectLabel)
                            <option value="{{ $projectId }}">{{ $projectLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-filament::icon-button
                    tag="a"
                    href="{{ $contentProjectsUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    icon="heroicon-o-arrow-top-right-on-square"
                    color="gray"
                    tooltip="{{ __('seo-content-ai::filament.articles_optimal.open_content_projects') }}"
                />
            </div>

            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.article_type') }}</label>
                <x-select x-model="assignType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                    @foreach ($assignTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>

            <div x-show="assignType === 'improve'" x-cloak>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.improve_instruction') }}</label>
                <textarea
                    x-model="rewriteNotes"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                    placeholder="{{ __('seo-content-ai::filament.projects.improve_instruction_placeholder') }}"
                ></textarea>
            </div>

            <div x-show="assignNeedsKeyword" x-cloak>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword') }}
                    <span class="text-rose-600">*</span>
                </label>
                <input
                    type="text"
                    x-model="assignFocusKeyword"
                    required
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                    placeholder="{{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword_placeholder') }}"
                />
                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword_help') }}</p>
            </div>

            <x-filament::button
                type="button"
                color="info"
                class="w-full"
                x-on:click="submitSidebarAssign()"
                x-bind:disabled="! canSubmitAssign()"
                x-bind:class="canSubmitAssign() ? '' : 'opacity-50 pointer-events-none'"
            >
                {{ __('seo-content-ai::filament.article_list.assign') }}
            </x-filament::button>
        </div>
    </aside>

</div>
