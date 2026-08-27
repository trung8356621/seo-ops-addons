@php
    $detail = $this->getDetail();
    $keywords = $this->getKeywords();
    $dnaMap = $this->getKeywordDnaMap();
    $resolver = $this->tagResolver();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-cluster-index-page max-w-full">
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-5">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <a href="{{ $this->backUrl() }}" class="topic-index-link text-sm">← {{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}</a>

        @if ($detail)
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-gray-950 dark:text-white">{{ $detail['label'] }}</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ number_format((int) $detail['keyword_count']) }} {{ __('seo-content-ai::filament.keyword.topic_col_keywords') }}
                        · {{ number_format((int) $detail['article_count']) }} {{ __('seo-content-ai::filament.keyword.topic_col_articles') }}
                        · {{ number_format((int) $detail['internal_links']) }} internal links
                    </p>
                </div>
                @if ($this->canDissolveCluster())
                    @php $dissolveClusterKey = (string) ($detail['cluster_key'] ?? ''); @endphp
                    <x-filament::button
                        type="button"
                        color="danger"
                        outlined
                        wire:click='openDissolveConfirm(@js($dissolveClusterKey))'
                    >
                        {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                    </x-filament::button>
                @endif
            </header>

            <div class="topic-index-stats">
                <div class="topic-index-stat">
                    <div class="topic-index-stat__label">Primary keyword</div>
                    <div class="topic-index-stat__value" style="font-size:1rem">{{ $detail['primary_keyword'] }}</div>
                </div>
                <div class="topic-index-stat">
                    <div class="topic-index-stat__label">Coverage</div>
                    <div class="topic-index-stat__value capitalize" style="font-size:1rem">{{ $detail['coverage'] }}</div>
                </div>
                <div class="topic-index-stat">
                    <div class="topic-index-stat__label">Intent</div>
                    <div class="topic-index-stat__value capitalize" style="font-size:1rem">{{ $detail['intent'] !== '' ? $detail['intent'] : '—' }}</div>
                </div>
                <div class="topic-index-stat">
                    <div class="topic-index-stat__label">Last analyzed</div>
                    <div class="topic-index-stat__value" style="font-size:1rem">{{ $detail['last_analyzed'] ?: '—' }}</div>
                </div>
            </div>

            <section class="topic-index-stat">
                <h2 class="topic-index-stat__label mb-2">Intent</h2>
                @forelse ($detail['intent_counts'] as $intent => $count)
                    <div class="flex items-center justify-between py-1 text-sm">
                        <span class="capitalize">{{ $intent }}</span>
                        <span class="font-medium">{{ number_format((int) $count) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">—</p>
                @endforelse
            </section>

            @if (! empty($detail['idea_coverage']))
                @php $idea = $detail['idea_coverage']; @endphp
                <section class="topic-index-stat space-y-3">
                    <h2 class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_idea_coverage_title') }}</h2>

                    <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ __('seo-content-ai::filament.keyword.topic_idea_core_topic') }}</div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ number_format((int) $idea['base_keyword_count']) }} KW
                            · {{ number_format((int) $idea['base_article_count']) }} {{ __('seo-content-ai::filament.keyword.topic_col_articles') }}
                            · {{ __('seo-content-ai::filament.keyword.topic_content_coverage_'.$idea['base_content_coverage']) }}
                        </div>
                    </div>

                    @if (! empty($idea['dna_branches']))
                        <div class="topic-index-table-wrap">
                            <table class="topic-index-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('seo-content-ai::filament.keyword.topic_idea_col_dna') }}</th>
                                        <th>{{ __('seo-content-ai::filament.keyword.topic_idea_col_keywords') }}</th>
                                        <th>{{ __('seo-content-ai::filament.keyword.topic_idea_col_articles') }}</th>
                                        <th>{{ __('seo-content-ai::filament.keyword.topic_idea_col_coverage') }}</th>
                                        <th>{{ __('seo-content-ai::filament.keyword.topic_idea_col_examples') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($idea['dna_branches'] as $branch)
                                        <tr>
                                            <td>{{ $branch['value'] }}</td>
                                            <td class="topic-index-num">{{ number_format((int) $branch['keyword_count']) }}</td>
                                            <td class="topic-index-num">{{ number_format((int) $branch['article_count']) }}</td>
                                            <td>
                                                <span class="topic-index-pill topic-index-pill--{{ $branch['content_coverage'] === 'uncovered' ? 'weak' : ($branch['content_coverage'] === 'dense' ? 'strong' : 'medium') }}">
                                                    {{ __('seo-content-ai::filament.keyword.topic_content_coverage_'.$branch['content_coverage']) }}
                                                </span>
                                            </td>
                                            <td class="text-xs text-gray-500">
                                                {{ implode(' · ', array_slice($branch['examples'] ?? [], 0, 2)) ?: '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @elseif (! empty($detail['dna_coverage']))
                <section class="topic-index-stat">
                    <h2 class="topic-index-stat__label mb-2">{{ __('seo-content-ai::filament.keyword.topic_dna_branches') }}</h2>
                    @foreach ($detail['dna_coverage'] as $branch)
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span>{{ $branch['value'] }}</span>
                            <span class="font-medium">{{ number_format((int) ($branch['count'] ?? 0)) }}</span>
                        </div>
                    @endforeach
                </section>
            @endif

            <div class="topic-index-table-wrap">
                <table class="topic-index-table">
                    <thead>
                        <tr>
                            <th>{{ __('seo-content-ai::filament.keyword.phrase_short') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_dna') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.operational_tags') }}</th>
                            <th>Intent</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_articles') }}</th>
                            <th>Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($keywords as $keyword)
                            @php
                                $tags = $resolver->displayTags($keyword);
                                $intent = (string) ($keyword->seoClassification?->seo_intent ?? '');
                                $articles = (int) ($keyword->linked_articles_count ?? 0);
                                $dnaValues = $dnaMap[(int) $keyword->id] ?? [];
                            @endphp
                            <tr>
                                <td>{{ $keyword->phrase }}</td>
                                <td>
                                    @if ($dnaValues === [])
                                        <span class="text-xs text-gray-400">—</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($dnaValues as $dnaVal)
                                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $dnaVal }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($tags as $tag)
                                            <span class="{{ $tag['badge_class'] }}">{{ $tag['label'] }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="capitalize">{{ $intent !== '' ? $intent : '—' }}</td>
                                <td class="topic-index-num">{{ $articles > 0 ? $articles : '—' }}</td>
                                <td>{{ $articles > 0 ? 'Có' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div>{{ $keywords->links() }}</div>

            @include('seo-content-ai::filament.resources.keywords.pages.partials.dissolve-cluster-modal')
        @endif
    </div>
</x-filament-panels::page>
