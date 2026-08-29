@php
    $cssPath = base_path('addons/seo/resources/css/keyword-workspace.css');
    $tabCounts = $this->getTriageTabCounts();
    $paginator = $this->getTriagePaginator();
@endphp

<x-filament-panels::page class="keyword-workspace-page link-triage-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="keyword-workspace-shell mt-5 space-y-5">
    <div class="link-triage-workspace space-y-5">
        <div class="link-triage-filter-bar">
            <div class="link-triage-filter-pills" role="tablist" aria-label="{{ __('seo-content-ai::filament.keyword.link_triage_filter_heading') }}">
                @foreach ([
                    'all_issues' => __('seo-content-ai::filament.keyword.link_triage_tab_all_issues'),
                    'broken' => __('seo-content-ai::filament.keyword.link_triage_tab_broken'),
                    'weak_context' => __('seo-content-ai::filament.keyword.link_triage_tab_weak_context'),
                    'external' => __('seo-content-ai::filament.keyword.link_triage_tab_external'),
                ] as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:click="setTriageFilter('{{ $tabKey }}')"
                        wire:loading.attr="disabled"
                        wire:target="setTriageFilter,keywordWorkspaceSiteId"
                        role="tab"
                        aria-selected="{{ $triageFilter === $tabKey ? 'true' : 'false' }}"
                        @class([
                            'link-triage-filter-pill',
                            'is-active' => $triageFilter === $tabKey,
                        ])
                    >
                        <span>{{ $tabLabel }}</span>
                        <span class="link-triage-filter-pill__count">{{ $tabCounts[$tabKey] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <x-seo-content-ai::list-table-loading-shell
            class="link-triage-table-shell overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40"
            preset="livewire-page"
            targets="setTriageFilter,triageFilter,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged"
        >
            @if ($paginator->isEmpty())
                <div class="px-6 py-14 text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.keyword.link_triage_empty') }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="link-triage-table min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.link_triage_col_keyword_source') }}</th>
                                <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.link_triage_col_status') }}</th>
                                <th scope="col" class="link-triage-th">{{ __('seo-content-ai::filament.keyword.link_triage_col_target') }}</th>
                                <th scope="col" class="link-triage-th link-triage-th--actions">{{ __('seo-content-ai::filament.keyword.link_triage_col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white dark:divide-white/10 dark:bg-gray-900/20">
                            @foreach ($paginator as $row)
                                @include('seo-content-ai::filament.resources.keywords.pages.partials.link-triage-table-row', [
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

    <x-filament-actions::modals />
</x-filament-panels::page>
