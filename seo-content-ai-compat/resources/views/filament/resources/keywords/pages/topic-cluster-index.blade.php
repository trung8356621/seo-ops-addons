@php
    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopicClusters();
    $canDissolve = $this->canDissolveCluster();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterPollAttr = $reclusterRunning ? 'wire:poll.5s="pollReclusterResult"' : '';
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-cluster-index-page max-w-full">
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-5" {!! $reclusterPollAttr !!}>
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <h1 class="sr-only">{{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}</h1>

        <div class="topic-index-stats">
            <div class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_clusters') }}</div>
                <div class="topic-index-stat__value">{{ number_format((int) $summary['topic_clusters']) }}</div>
            </div>
            <div class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_clustered') }}</div>
                <div class="topic-index-stat__value">{{ number_format((int) $summary['clustered']) }}</div>
            </div>
            <a href="{{ $this->unclusteredUrl() }}" class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_unclustered') }}</div>
                <div class="topic-index-stat__value is-accent">{{ number_format((int) $summary['unclustered']) }}</div>
                <div class="topic-index-stat__meta">{{ __('seo-content-ai::filament.keyword.topic_summary_unclustered_hint') }}</div>
            </a>
        </div>

        @if (((int) ($summary['unclassified_keywords'] ?? 0)) > 0 || ((int) ($summary['non_seo_keywords'] ?? 0)) > 0)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.topic_summary_quality_line', [
                    'unclassified' => number_format((int) ($summary['unclassified_keywords'] ?? 0)),
                    'non_seo' => number_format((int) ($summary['non_seo_keywords'] ?? 0)),
                ]) }}
            </p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="topic-index-filters flex-1">
                <input type="search" wire:model.live.debounce.400ms="clusterSearch" class="topic-index-input" placeholder="{{ __('seo-content-ai::filament.keyword.topic_search_cluster') }}">
                <x-select size="sm" wire:model.live="coverageFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_coverage_any') }}</option>
                    <option value="strong">Strong</option>
                    <option value="medium">Medium</option>
                    <option value="weak">Weak</option>
                    <option value="unknown">Unknown</option>
                </x-select>
                <label class="topic-index-check">
                    <input type="checkbox" wire:model.live="hasArticles">
                    {{ __('seo-content-ai::filament.keyword.topic_has_articles') }}
                </label>
            </div>
            @if ($canRecluster)
                <div class="flex max-w-md flex-col items-end gap-1.5">
                    <p class="text-right text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}
                    </p>
                    @if ($confirmRecluster)
                        <div class="flex flex-wrap items-center justify-end gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-950">
                            <span class="text-xs text-amber-900 dark:text-amber-100">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_confirm') }}
                            </span>
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                wire:click="cancelReclusterConfirm"
                                wire:loading.attr="disabled"
                                wire:target="confirmDispatchReclusterTopicClusters"
                            >
                                {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="warning"
                                wire:click="confirmDispatchReclusterTopicClusters"
                                wire:loading.attr="disabled"
                                wire:target="confirmDispatchReclusterTopicClusters"
                            >
                                <span wire:loading.remove wire:target="confirmDispatchReclusterTopicClusters">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                </span>
                                <span wire:loading wire:target="confirmDispatchReclusterTopicClusters">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_running') }}
                                </span>
                            </x-filament::button>
                        </div>
                    @else
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="warning"
                            wire:click="openReclusterConfirm"
                            wire:loading.attr="disabled"
                            wire:target="confirmDispatchReclusterTopicClusters"
                            :disabled="$reclusterRunning"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>

        @if ($reclusterStatus === 'queued')
            <p class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_queued') }}
            </p>
        @elseif ($reclusterStatus === 'running' || $reclusterRunning)
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_running') }}
            </p>
        @elseif ($reclusterStatus === 'completed')
            @php $m = $this->reclusterResult['metrics'] ?? []; @endphp
            <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_result_title') }}:
                {{ number_format((int) ($m['keywords_processed'] ?? 0)) }} processed ·
                {{ number_format((int) ($m['clusters_merged'] ?? 0)) }} merged ·
                {{ number_format((int) ($m['clusters_before'] ?? 0)) }}→{{ number_format((int) ($m['clusters_after'] ?? 0)) }} clusters ·
                {{ number_format((int) ($m['dna_created'] ?? 0)) }} DNA
            </p>
        @elseif ($reclusterStatus === 'failed')
            <p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_failed_title') }}
                @if (! empty($this->reclusterResult['error']))
                    — {{ $this->reclusterResult['error'] }}
                @endif
            </p>
        @endif

        @if ($clusters->total() === 0)
            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                {{ __('seo-content-ai::filament.keyword.topic_empty_clusters') }}
            </p>
        @else
            <div class="topic-index-table-wrap">
                <table class="topic-index-table">
                    <thead>
                        <tr>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_cluster') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_keywords') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_dna_count') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_dna_covered') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_dna_uncovered') }}</th>
                            <th>{{ __('seo-content-ai::filament.keyword.topic_col_articles') }}</th>
                            <th>Intent</th>
                            <th>Coverage</th>
                            @if ($canDissolve)
                                <th class="w-28"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clusters as $row)
                            @php
                                $coverage = strtolower((string) ($row['coverage'] ?? 'unknown'));
                                $pill = match ($coverage) {
                                    'strong', 'healthy', 'saturated' => 'topic-index-pill--strong',
                                    'medium' => 'topic-index-pill--medium',
                                    'weak', 'missing' => 'topic-index-pill--weak',
                                    default => 'topic-index-pill--unknown',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ $this->clusterUrl($row['cluster_key']) }}" class="topic-index-link">
                                        {{ $row['label'] }}
                                    </a>
                                    <div class="topic-index-meta">{{ number_format((int) $row['keyword_count']) }} keywords</div>
                                </td>
                                <td class="topic-index-num">{{ number_format((int) $row['keyword_count']) }}</td>
                                <td class="topic-index-num">{{ number_format((int) ($row['dna_branch_count'] ?? 0)) }}</td>
                                <td class="topic-index-num">{{ number_format((int) ($row['covered_branch_count'] ?? 0)) }}</td>
                                <td class="topic-index-num">{{ number_format((int) ($row['uncovered_branch_count'] ?? 0)) }}</td>
                                <td class="topic-index-num">{{ number_format((int) $row['article_count']) }}</td>
                                <td class="capitalize">{{ $row['intent'] !== '' ? $row['intent'] : '—' }}</td>
                                <td><span class="topic-index-pill {{ $pill }}">{{ $row['coverage'] }}</span></td>
                                @if ($canDissolve)
                                    <td class="text-right">
                                        @include('seo-content-ai::filament.resources.keywords.pages.partials.dissolve-cluster-row-action', [
                                            'clusterKey' => $row['cluster_key'],
                                        ])
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div>{{ $clusters->links() }}</div>
        @endif

        @include('seo-content-ai::filament.resources.keywords.pages.partials.dissolve-cluster-modal')
    </div>
</x-filament-panels::page>
