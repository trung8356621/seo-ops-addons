@php
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $performanceCss = base_path('addons/search-intelligence/resources/css/performance-hub.css');
    $cannibalization = $this->cannibalizationRows;
@endphp

<x-filament-panels::page class="keyword-workspace-page performance-hub-page">
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif
    @if (is_readable($performanceCss))
        <style>{!! file_get_contents($performanceCss) !!}</style>
    @endif

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="keyword-workspace-shell mt-5 space-y-5">
        <header class="performance-hub-header">
            <div>
                <h1 class="performance-hub-title">{{ __('seo-content-ai::filament.keyword.cannibalization_title') }}</h1>
                <p class="performance-hub-subtitle">{{ __('seo-content-ai::filament.keyword.cannibalization_subtitle') }}</p>
            </div>
        </header>

        <x-seo-content-ai::list-table-loading-shell
            preset="livewire-page"
            targets="keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged"
        >
        <section class="performance-hub-panel">
            <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.cannibalization_hint') }}</p>
            <div class="performance-hub-table-wrap">
                <table class="performance-hub-table">
                    <thead>
                        <tr>
                            <th>{{ __('seo-content-ai::filament.performance_hub.col_focus_keyword') }}</th>
                            <th>{{ __('seo-content-ai::filament.performance_hub.col_article_count') }}</th>
                            <th>{{ __('seo-content-ai::filament.performance_hub.col_articles') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cannibalization as $row)
                            <tr wire:key="cannibalization-{{ md5((string) ($row['phrase'] ?? '')) }}">
                                <td class="font-medium text-gray-900 dark:text-white">{{ $row['phrase'] ?? '' }}</td>
                                <td>{{ (int) ($row['article_count'] ?? 0) }}</td>
                                <td>
                                    <ul class="space-y-1">
                                        @foreach (($row['articles'] ?? []) as $article)
                                            <li>
                                                <a
                                                    href="{{ $article['url'] ?? '#' }}"
                                                    class="text-emerald-600 hover:underline dark:text-emerald-400"
                                                >
                                                    {{ $article['title'] ?? __('seo-content-ai::filament.performance_hub.untitled_article') }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="performance-hub-table-empty">
                                    {{ __('seo-content-ai::filament.performance_hub.cannibalization_empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        </x-seo-content-ai::list-table-loading-shell>
    </div>
</x-filament-panels::page>
