@php
    $detail = $this->getDetail();
    $keywords = $this->getKeywords();
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
                    <x-filament::button
                        type="button"
                        color="danger"
                        outlined
                        wire:click="openDissolveConfirm({{ json_encode($detail['cluster_key']) }})"
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

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="topic-index-stat">
                    <h2 class="topic-index-stat__label mb-2">{{ __('seo-content-ai::filament.keyword.topic_group_distribution') }}</h2>
                    @forelse ($detail['groups'] as $group)
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span>{{ $group['label'] }}</span>
                            <span class="font-medium">{{ number_format((int) $group['keyword_count']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">—</p>
                    @endforelse
                </section>
                <section class="topic-index-stat">
                    <h2 class="topic-index-stat__label mb-2">Intent</h2>
                    @foreach ($detail['intent_counts'] as $intent => $count)
                        <div class="flex items-center justify-between py-1 text-sm">
                            <span class="capitalize">{{ $intent }}</span>
                            <span class="font-medium">{{ number_format((int) $count) }}</span>
                        </div>
                    @endforeach
                </section>
            </div>

            <div class="topic-index-table-wrap">
                <table class="topic-index-table">
                    <thead>
                        <tr>
                            <th>{{ __('seo-content-ai::filament.keyword.phrase_short') }}</th>
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
                            @endphp
                            <tr>
                                <td>{{ $keyword->phrase }}</td>
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
