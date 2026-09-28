@php
    $cssPath = base_path('addons/seo/resources/css/keyword-workspace.css');
    $categoryCounts = $this->getExternalCategoryCounts();
    $riskCounts = $this->getRiskCounts();
    $paginator = $this->getExternalPaginator();

    $typeFilters = [
        'all' => __('seo-content-ai::filament.keyword.external_filter_all'),
        'managed_cross_site' => __('seo-content-ai::filament.keyword.external_filter_managed'),
        'reference' => __('seo-content-ai::filament.keyword.external_filter_reference'),
        'needs_review' => __('seo-content-ai::filament.keyword.external_filter_review'),
    ];

    $riskFilters = [
        'all' => __('seo-content-ai::filament.keyword.risk_filter_all'),
        'safe' => __('seo-content-ai::filament.keyword.risk_filter_safe'),
        'low' => __('seo-content-ai::filament.keyword.risk_filter_low'),
        'review' => __('seo-content-ai::filament.keyword.risk_filter_review'),
    ];
@endphp

<x-filament-panels::page class="keyword-workspace-page keyword-external-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="keyword-workspace-shell mt-5 space-y-5" data-keyword-external-view>
        <div class="link-triage-workspace space-y-5">
            <p class="keyword-external-lead">
                {{ __('seo-content-ai::filament.keyword.external_lead') }}
            </p>

            <div class="space-y-3">
                {{-- Filter Group 1: TYPE --}}
                <div class="link-triage-filter-bar">
                    <div class="flex items-center gap-1.5 self-center text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mr-2">
                        <span>{{ __('seo-content-ai::filament.keyword.external_filter_group_type') }}</span>
                    </div>
                    <div class="link-triage-filter-pills" role="tablist" aria-label="{{ __('seo-content-ai::filament.keyword.external_filter_heading') }}">
                        @foreach ($typeFilters as $tabKey => $tabLabel)
                            @php
                                $isTypeActive = $risk === '' && ($filter === $tabKey || ($filter === '' && $tabKey === 'all'));
                            @endphp
                            <button
                                type="button"
                                wire:click="setTypeFilter('{{ $tabKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="setTypeFilter,setRiskFilter,setExternalFilter,keywordWorkspaceSiteId"
                                role="tab"
                                aria-selected="{{ $isTypeActive ? 'true' : 'false' }}"
                                data-external-filter="{{ $tabKey }}"
                                @class([
                                    'link-triage-filter-pill',
                                    'is-active' => $isTypeActive,
                                ])
                            >
                                <span>{{ $tabLabel }}</span>
                                <span class="link-triage-filter-pill__count">{{ $categoryCounts[$tabKey] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Filter Group 2: RISK --}}
                <div class="link-triage-filter-bar">
                    <div class="flex items-center gap-1.5 self-center text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mr-2">
                        <span>{{ __('seo-content-ai::filament.keyword.external_filter_group_risk') }}</span>
                    </div>
                    <div class="link-triage-filter-pills" role="tablist" aria-label="{{ __('seo-content-ai::filament.keyword.risk_filter_heading') }}">
                        @foreach ($riskFilters as $tabKey => $tabLabel)
                            @php
                                $isRiskActive = $risk === $tabKey;
                            @endphp
                            <button
                                type="button"
                                wire:click="setRiskFilter('{{ $tabKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="setTypeFilter,setRiskFilter,setExternalFilter,keywordWorkspaceSiteId"
                                role="tab"
                                aria-selected="{{ $isRiskActive ? 'true' : 'false' }}"
                                data-risk-filter="{{ $tabKey }}"
                                @class([
                                    'link-triage-filter-pill',
                                    'is-active' => $isRiskActive,
                                ])
                            >
                                <span>{{ $tabLabel }}</span>
                                <span class="link-triage-filter-pill__count">{{ $riskCounts[$tabKey] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <x-seo-content-ai::list-table-loading-shell
                class="link-triage-table-shell overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40"
                preset="livewire-page"
                targets="setTypeFilter,setRiskFilter,setExternalFilter,filter,risk,keywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged"
            >
                @if (($this->resolveKeywordWorkspaceSiteId() ?? 0) <= 0)
                    <div class="px-6 py-14 text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.external_need_site') }}
                        </p>
                    </div>
                @elseif ($paginator->isEmpty())
                    <div class="px-6 py-14 text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.external_empty') }}
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="link-triage-table min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.external_col_source') }}</th>
                                    <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.external_col_status') }}</th>
                                    <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.external_col_destination') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-white/10 dark:bg-gray-900/20">
                                @foreach ($paginator as $row)
                                    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-external-row', [
                                        'row' => $row,
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($paginator->hasPages())
                        <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10">
                            {{ $paginator->links() }}
                        </div>
                    @endif
                @endif
            </x-seo-content-ai::list-table-loading-shell>
        </div>
    </div>
</x-filament-panels::page>
