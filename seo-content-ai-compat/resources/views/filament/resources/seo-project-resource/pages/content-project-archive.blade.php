@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive> $archives */
    $archives = $this->archives;
    $siteFilterOptions = $this->getSiteFilterOptions();
    $ownerFilterOptions = $this->getOwnerFilterOptions();
    $archivedByFilterOptions = $this->getArchivedByFilterOptions();
    $monthFilterOptions = $this->getMonthFilterOptions();
    $yearFilterOptions = $this->getYearFilterOptions();
    $showSiteFilter = count($siteFilterOptions) > 1;
    $activeFilterCount = $this->getActiveFilterCount($showSiteFilter);
    $vaultPresenter = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveVaultListPresenter::class;
@endphp

<x-filament-panels::page>
    <div class="w-full space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.projects.archive_dashboard_heading') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.projects.archive_dashboard_description') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
            <button
                type="button"
                wire:click="setActiveTab('projects')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm' => $activeTab === 'projects',
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $activeTab !== 'projects',
                ])
            >
                {{ __('seo-content-ai::filament.projects.archive_tab_projects') }}
            </button>
            <button
                type="button"
                wire:click="setActiveTab('legacy')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm' => $activeTab === 'legacy',
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $activeTab !== 'legacy',
                ])
            >
                {{ __('seo-content-ai::filament.projects.archive_tab_legacy') }}
            </button>
        </div>

        @if ($activeTab === 'projects')
            @php
                $monthOptions = $this->getPlanningMonthOptions();
                $domainChart = $this->getArchivedDomainChart();
                $writerChart = $this->getArchivedWriterChart();
            @endphp

            <div class="mb-2 flex flex-wrap items-center gap-2">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200" for="archive-planning-month">
                    {{ __('seo-content-ai::filament.projects.planning_month') }}:
                </label>
                <x-select
                    id="archive-planning-month"
                    wire:model.live="planningMonth"
                    size="inline"
                    class="min-w-[8.5rem] text-sm"
                >
                    @foreach ($monthOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-select>
            </div>

            <x-seo-content-ai::content-project-month-charts
                :domain-chart="$domainChart"
                :writer-chart="$writerChart"
                domain-empty-key="seo-content-ai::filament.projects.chart_archived_domain_empty"
                writer-empty-key="seo-content-ai::filament.projects.chart_archived_writer_empty"
            />

            <div class="w-full space-y-4" x-data="{ filtersOpen: false }">
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <label class="sr-only" for="archive-project-search">{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}</label>
                        <form wire:submit="applySearch" class="w-full">
                            <input
                                id="archive-project-search"
                                type="search"
                                wire:model="searchInput"
                                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                placeholder="{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}"
                                autocomplete="off"
                            >
                        </form>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                        <button
                            type="button"
                            wire:click="exportMonth"
                            wire:loading.attr="disabled"
                            wire:target="exportMonth"
                            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 disabled:opacity-50 dark:text-primary-300 dark:ring-primary-500/40 dark:hover:bg-primary-500/10"
                        >
                            <x-filament::loading-indicator class="h-4 w-4" wire:loading wire:target="exportMonth" />
                            <span wire:loading.remove wire:target="exportMonth">{{ __('seo-content-ai::filament.projects.archive_export_month') }}</span>
                            <span wire:loading wire:target="exportMonth">{{ __('seo-content-ai::filament.projects.archive_export_month_running') }}</span>
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-800"
                            @click="filtersOpen = !filtersOpen"
                            :aria-expanded="filtersOpen.toString()"
                        >
                            <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
                            @if ($activeFilterCount > 0)
                                {{ __('seo-content-ai::filament.projects.archive_filters_with_count', ['count' => $activeFilterCount]) }}
                            @else
                                {{ __('seo-content-ai::filament.projects.archive_filters') }}
                            @endif
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-4 w-4 transition-transform"
                                x-bind:class="filtersOpen ? 'rotate-180' : ''"
                            />
                        </button>
                    </div>
                </div>

                <div
                    x-show="filtersOpen"
                    x-cloak
                    class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50/80 p-3 dark:border-gray-700 dark:bg-gray-800/40 md:grid-cols-2 xl:grid-cols-3"
                >
                    @if ($showSiteFilter)
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-site-filter">
                                {{ __('seo-content-ai::filament.article_list.domain') }}
                            </label>
                            <x-select id="archive-project-site-filter" wire:model.live="siteFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_domain_all') }}</option>
                                @foreach ($siteFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-month-filter">
                            {{ __('seo-content-ai::filament.projects.month') }}
                        </label>
                        <x-select id="archive-project-month-filter" wire:model.live="monthFilter" class="w-full rounded-lg text-sm">
                            <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_month_all') }}</option>
                            @foreach ($monthFilterOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-year-filter">
                            {{ __('seo-content-ai::filament.projects.year') }}
                        </label>
                        <x-select id="archive-project-year-filter" wire:model.live="yearFilter" class="w-full rounded-lg text-sm">
                            <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_year_all') }}</option>
                            @foreach ($yearFilterOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    @if ($ownerFilterOptions !== [])
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-owner-filter">
                                {{ __('seo-content-ai::filament.projects.owner') }}
                            </label>
                            <x-select id="archive-project-owner-filter" wire:model.live="ownerFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_owner_all') }}</option>
                                @foreach ($ownerFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    @if ($archivedByFilterOptions !== [])
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-archived-by-filter">
                                {{ __('seo-content-ai::filament.projects.archive_filter_archived_by') }}
                            </label>
                            <x-select id="archive-project-archived-by-filter" wire:model.live="archivedByFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_archived_by_all') }}</option>
                                @foreach ($archivedByFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    @if ($activeFilterCount > 0)
                        <div class="flex items-end md:col-span-2 xl:col-span-3">
                            <button
                                type="button"
                                wire:click="clearFilters"
                                wire:loading.attr="disabled"
                                wire:target="clearFilters"
                                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline disabled:opacity-50 dark:text-primary-400"
                            >
                                <x-filament::loading-indicator class="h-3.5 w-3.5" wire:loading wire:target="clearFilters" />
                                {{ __('seo-content-ai::filament.projects.archive_clear_filters') }}
                            </button>
                        </div>
                    @endif
                </div>

                <x-seo-content-ai::list-table-loading-shell
                    preset="livewire-page"
                    targets="search,applySearch,clearSearch,siteFilter,monthFilter,yearFilter,ownerFilter,archivedByFilter,clearFilters,setActiveTab,exportMonth"
                >
                <div class="w-full overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <table class="w-full min-w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800/60">
                            <tr>
                                <th class="w-[26%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.name') }}</th>
                                <th class="w-[14%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.owner') }}</th>
                                <th class="w-[8%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.month') }}</th>
                                <th class="w-[6%] px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_total') }}</th>
                                <th class="w-[14%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_index') }}</th>
                                <th class="w-[10%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_archived_at') }}</th>
                                <th class="w-[10%] px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_archived_by') }}</th>
                                <th class="w-[16%] px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($archives as $archive)
                                @php
                                    $ownerName = trim((string) ($archive->owner?->name ?? ''));
                                    $archivedByName = trim((string) ($archive->archivedByUser?->name ?? ''));
                                    $month = (int) ($archive->project_month ?? 0);
                                    $year = (int) ($archive->project_year ?? 0);
                                    $period = ($month > 0 && $year > 0) ? sprintf('%02d/%d', $month, $year) : '—';
                                    $listTotal = $vaultPresenter::listTotal($archive);
                                    $indexSummary = $vaultPresenter::indexSummary($archive);
                                @endphp
                                <tr wire:key="archive-row-{{ $archive->id }}">
                                    <td class="truncate px-3 py-2 font-semibold text-gray-950 dark:text-white" title="{{ $archive->project_name ?: '' }}">{{ $archive->project_name ?: '—' }}</td>
                                    <td class="truncate px-3 py-2 text-gray-700 dark:text-gray-200" title="{{ $ownerName }}">{{ $ownerName !== '' ? $ownerName : '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $period }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $listTotal }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">
                                        <div class="tabular-nums font-medium text-gray-950 dark:text-white">
                                            {{ $indexSummary['indexed_count'] }} / {{ $indexSummary['total'] }}
                                        </div>
                                        @if ($indexSummary['has_indexed'])
                                            @if ($indexSummary['latest_indexed_at_label'] !== null)
                                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('seo-content-ai::filament.projects.archive_index_latest', ['date' => $indexSummary['latest_indexed_at_label']]) }}
                                                </div>
                                            @endif
                                            @if ($indexSummary['reindexed_count'] > 0)
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('seo-content-ai::filament.projects.archive_index_reindex', ['count' => $indexSummary['reindexed_count']]) }}
                                                </div>
                                            @endif
                                        @else
                                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.archive_preview_index_none') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-200">{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::formatTaskTimestamp($archive->archived_at) }}</td>
                                    <td class="truncate px-3 py-2 text-gray-700 dark:text-gray-200" title="{{ $archivedByName }}">{{ $archivedByName !== '' ? $archivedByName : '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a
                                                href="{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('archive-preview', ['archive' => $archive->id]) }}"
                                                class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-800"
                                            >
                                                {{ __('seo-content-ai::filament.projects.archive_preview') }}
                                            </a>
                                            <button
                                                type="button"
                                                wire:click="exportArchive({{ $archive->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="exportArchive({{ $archive->id }})"
                                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 disabled:opacity-50 dark:text-primary-300 dark:ring-primary-500/40 dark:hover:bg-primary-500/10"
                                            >
                                                <x-filament::loading-indicator class="h-3.5 w-3.5" wire:loading wire:target="exportArchive({{ $archive->id }})" />
                                                <span wire:loading.remove wire:target="exportArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_export') }}</span>
                                                <span wire:loading wire:target="exportArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_export_running') }}</span>
                                            </button>
                                            @if ($this->canRestoreArchives())
                                                <button
                                                    type="button"
                                                    wire:click="restoreArchive({{ $archive->id }})"
                                                    wire:confirm="{{ __('seo-content-ai::filament.projects.archive_restore_confirm') }}"
                                                    wire:loading.attr="disabled"
                                                    wire:target="restoreArchive({{ $archive->id }})"
                                                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-warning-700 ring-1 ring-warning-300 hover:bg-warning-50 disabled:opacity-50 dark:text-warning-300 dark:ring-warning-500/40 dark:hover:bg-warning-500/10"
                                                >
                                                    <x-filament::loading-indicator class="h-3.5 w-3.5" wire:loading wire:target="restoreArchive({{ $archive->id }})" />
                                                    <span wire:loading.remove wire:target="restoreArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_restore') }}</span>
                                                    <span wire:loading wire:target="restoreArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_restore_running') }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.projects.archive_projects_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($archives->hasPages())
                    <div>
                        {{ $archives->links() }}
                    </div>
                @endif
                </x-seo-content-ai::list-table-loading-shell>
            </div>
        @else
            <div class="w-full overflow-visible">
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    {{ __('seo-content-ai::filament.projects.archive_legacy_banner') }}
                </div>

                @include('seo-content-ai::filament.resources.seo-project-resource.partials.archive-dashboard', [
                    'siteId' => (int) ($this->siteId ?? 0),
                    'siteIds' => $this->scopedSiteIds,
                    'canReopen' => $this->canReopenArchivedArticles(),
                ])
            </div>
        @endif
    </div>
</x-filament-panels::page>
