@php
    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $groups = $this->getGroups();
    $editing = $this->getEditingGroup();
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
            </a>
            <div class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_groups') }}</div>
                <div class="topic-index-stat__value">{{ number_format((int) $summary['system_groups'] + (int) $summary['custom_groups']) }}</div>
            </div>
        </div>

        <div class="topic-index-tabs" role="tablist">
            <button type="button" class="topic-index-tab {{ $this->section === 'clusters' ? 'is-active' : '' }}" wire:click="showClusters">
                {{ __('seo-content-ai::filament.keyword.topic_tab_clusters') }}
            </button>
            <button type="button" class="topic-index-tab {{ $this->section === 'groups' ? 'is-active' : '' }}" wire:click="showGroups">
                {{ __('seo-content-ai::filament.keyword.topic_tab_groups') }}
            </button>
        </div>

        @if ($this->section === 'clusters')
            <div class="topic-index-filters">
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
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_articles') }}</th>
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_groups') }}</th>
                                <th>Intent</th>
                                <th>Coverage</th>
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
                                    <td class="topic-index-num">{{ number_format((int) $row['article_count']) }}</td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($row['groups'], 0, 3) as $groupLabel)
                                                <span class="ws-badge ws-badge--compact ws-badge--gray">{{ $groupLabel }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="capitalize">{{ $row['intent'] !== '' ? $row['intent'] : '—' }}</td>
                                    <td><span class="topic-index-pill {{ $pill }}">{{ $row['coverage'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $clusters->links() }}</div>
            @endif
        @else
            <div class="topic-index-filters">
                <input type="search" wire:model.live.debounce.400ms="groupSearch" class="topic-index-input" placeholder="{{ __('seo-content-ai::filament.keyword.topic_search_group') }}">
                <x-select size="sm" wire:model.live="groupTypeFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_group_type_any') }}</option>
                    <option value="system">System</option>
                    <option value="custom">Custom</option>
                </x-select>
                <input type="text" wire:model="newGroupLabel" class="topic-index-input" placeholder="{{ __('seo-content-ai::filament.keyword.topic_new_group') }}">
                <x-filament::button type="button" size="sm" wire:click="createCustomGroup" wire:loading.attr="disabled">
                    {{ __('seo-content-ai::filament.keyword.topic_create_group') }}
                </x-filament::button>
            </div>

            @if ($groups === [])
                <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                    {{ __('seo-content-ai::filament.keyword.topic_empty_groups') }}
                </p>
            @else
                <div class="topic-index-table-wrap">
                    <table class="topic-index-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_group') }}</th>
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_type') }}</th>
                                <th>Rules</th>
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_keywords') }}</th>
                                <th>{{ __('seo-content-ai::filament.keyword.topic_col_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                <tr>
                                    <td>
                                        <button type="button" class="topic-index-link" wire:click="editGroup({{ $group['id'] }})">
                                            {{ $group['label'] }}
                                        </button>
                                    </td>
                                    <td class="capitalize">{{ $group['type'] }}</td>
                                    <td class="topic-index-num">{{ number_format((int) $group['rules']) }}</td>
                                    <td class="topic-index-num">{{ number_format((int) $group['keywords']) }}</td>
                                    <td>
                                        <button type="button" class="topic-index-pill {{ $group['active'] ? 'topic-index-pill--strong' : 'topic-index-pill--unknown' }}" wire:click="toggleGroup({{ $group['id'] }})">
                                            {{ $group['active'] ? 'Active' : 'Inactive' }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($editing)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <h2 class="mb-3 text-sm font-semibold">{{ $editing->label }}</h2>
                    <ul class="mb-3 space-y-1 text-sm">
                        @foreach ($editing->rules as $rule)
                            <li class="flex items-center justify-between">
                                <span>{{ $rule->phrase }} <span class="text-xs text-gray-400">({{ $rule->match_type }})</span></span>
                                <button type="button" class="text-xs text-rose-600" wire:click="deleteRule({{ $rule->id }})">{{ __('seo-content-ai::filament.keyword.topic_remove_rule') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="topic-index-filters">
                        <input type="text" wire:model="newRulePhrase" class="topic-index-input" placeholder="canvas">
                        <x-filament::button type="button" size="sm" wire:click="addRuleToEditingGroup">{{ __('seo-content-ai::filament.keyword.topic_add_rule') }}</x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
