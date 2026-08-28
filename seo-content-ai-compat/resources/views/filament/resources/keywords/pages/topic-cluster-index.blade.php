@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $mcpPreview = $this->getMcpPreviewSummary();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopicClusters();
    $canDissolve = $this->canDissolveCluster();
    $canEditCanonical = $this->canEditClusterCanonical();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterPollAttr = $reclusterRunning ? 'wire:poll.5s="pollReclusterResult"' : '';
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-cluster-index-page max-w-full">
    <x-seo-content-ai::content-project-ops-styles />
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-5" {!! $reclusterPollAttr !!}>
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <h1 class="sr-only">{{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}</h1>

        <div class="topic-index-stats" wire:key="topic-index-stats-{{ $this->clusterDataEpoch }}">
            <div class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_clusters') }}</div>
                <div class="topic-index-stat__value">{{ number_format((int) $summary['topic_clusters']) }}</div>
            </div>
            <div class="topic-index-stat">
                <div class="topic-index-stat__label">{{ __('seo-content-ai::filament.keyword.topic_summary_mcp_topics') }}</div>
                <div class="topic-index-stat__value">{{ number_format((int) ($mcpPreview['total_topics'] ?? 0)) }}</div>
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

        <div class="cluster-mcp-preview" wire:key="cluster-mcp-preview-{{ $this->clusterDataEpoch }}">
            <div class="cluster-mcp-preview__label">{{ __('seo-content-ai::filament.keyword.topic_mcp_preview_label') }}</div>
            <div class="cluster-mcp-preview__value">
                {{ __('seo-content-ai::filament.keyword.topic_mcp_preview_line', [
                    'clusters' => number_format((int) $mcpPreview['cluster_count']),
                    'coverage' => rtrim(rtrim(number_format((float) $mcpPreview['coverage_percent'], 1, '.', ''), '0'), '.'),
                    'tokens' => number_format((int) $mcpPreview['estimated_tokens']),
                ]) }}
            </div>
        </div>

        @if (((int) ($summary['unclassified_keywords'] ?? 0)) > 0 || ((int) ($summary['non_seo_keywords'] ?? 0)) > 0)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.topic_summary_quality_line', [
                    'unclassified' => number_format((int) ($summary['unclassified_keywords'] ?? 0)),
                    'non_seo' => number_format((int) ($summary['non_seo_keywords'] ?? 0)),
                ]) }}
            </p>
        @endif

        @if ($this->clusterStateIsDirty())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ __('seo-content-ai::filament.keyword.topic_cluster_dirty_banner') }}
            </div>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="topic-index-create flex-1" x-data="{ exists: @js($this->quickCreateClusterExists()) }">
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        type="search"
                        wire:model.live.debounce.400ms="clusterSearch"
                        x-on:input="exists = false"
                        class="topic-index-input topic-index-input--wide"
                        placeholder="{{ __('seo-content-ai::filament.keyword.topic_search_or_create_cluster') }}"
                    >
                    @if ($canEditCanonical)
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="primary"
                            wire:click="quickCreateCluster"
                            wire:loading.attr="disabled"
                            wire:target="quickCreateCluster"
                            x-bind:disabled="exists || @js(trim($clusterSearch) === '')"
                        >
                            <span wire:loading.remove wire:target="quickCreateCluster">
                                {{ __('seo-content-ai::filament.keyword.topic_quick_create_action') }}
                            </span>
                            <span wire:loading wire:target="quickCreateCluster">
                                {{ __('seo-content-ai::filament.keyword.topic_quick_create_resolving') }}
                            </span>
                        </x-filament::button>
                        <span
                            wire:loading.remove
                            wire:target="clusterSearch,quickCreateCluster"
                            x-show="exists"
                            x-cloak
                            class="text-xs text-gray-500"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_quick_create_exists') }}
                        </span>
                    @endif
                </div>
                <div class="topic-index-filters mt-3">
                    <x-select size="sm" wire:model.live="clusterProjection">
                        <option value="mcp">{{ __('seo-content-ai::filament.keyword.topic_projection_mcp') }}</option>
                        <option value="seo">{{ __('seo-content-ai::filament.keyword.topic_projection_seo') }}</option>
                    </x-select>
                    <x-select size="sm" wire:model.live="coverageFilter">
                        <option value="">{{ __('seo-content-ai::filament.keyword.topic_coverage_any') }}</option>
                        <option value="strong">Strong</option>
                        <option value="medium">Medium</option>
                        <option value="weak">Weak</option>
                        <option value="unknown">Unknown</option>
                    </x-select>
                    <x-select size="sm" wire:model.live="clusterSort">
                        <option value="mcp_share_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_mcp_desc') }}</option>
                        <option value="mcp_share_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_mcp_asc') }}</option>
                        <option value="articles_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_articles_desc') }}</option>
                        <option value="articles_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_articles_asc') }}</option>
                        <option value="keywords_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_keywords_desc') }}</option>
                        <option value="keywords_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_keywords_asc') }}</option>
                        <option value="name_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_name_asc') }}</option>
                        <option value="name_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_name_desc') }}</option>
                    </x-select>
                    <label class="topic-index-check">
                        <input type="checkbox" wire:model.live="hasArticles">
                        {{ __('seo-content-ai::filament.keyword.topic_has_articles') }}
                    </label>
                </div>
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
                            <x-filament::button type="button" size="sm" color="gray" wire:click="cancelReclusterConfirm">
                                {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                            </x-filament::button>
                            <x-filament::button type="button" size="sm" color="warning" wire:click="confirmDispatchReclusterTopicClusters">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                            </x-filament::button>
                        </div>
                    @else
                        <x-filament::button type="button" size="sm" color="warning" wire:click="openReclusterConfirm" :disabled="$reclusterRunning">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterRunning)
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_running') }}
            </p>
        @elseif ($reclusterStatus === 'completed')
            @php $m = $this->reclusterResult['metrics'] ?? []; @endphp
            <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_result_title') }}:
                {{ number_format((int) ($m['keywords_processed'] ?? 0)) }} processed ·
                {{ number_format((int) ($m['clusters_merged'] ?? 0)) }} merged ·
                {{ number_format((int) ($m['clusters_before'] ?? 0)) }}→{{ number_format((int) ($m['clusters_after'] ?? 0)) }} clusters
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
            <div class="cluster-index-list">
                @foreach ($clusters as $row)
                    @php
                        $rowKey = (string) ($row['cluster_key'] ?? '');
                        $rowLabel = KeywordPhrasePresentation::present((string) ($row['label'] ?? $rowKey));
                        $share = (float) ($row['topical_share'] ?? 0);
                        $shareDisplay = rtrim(rtrim(number_format($share, 1, '.', ''), '0'), '.');
                        $mcpExcluded = (bool) ($row['mcp_excluded'] ?? false);
                        $seoExcluded = (bool) ($row['seo_excluded'] ?? false);
                        $isMcpGroup = (bool) ($row['is_mcp_group'] ?? false);
                        $mcpMembers = is_array($row['mcp_members'] ?? null) ? $row['mcp_members'] : [];
                        $mcpMemberCount = (int) ($row['mcp_member_count'] ?? count($mcpMembers));
                        $mcpGroup = is_array($row['mcp_group'] ?? null) ? $row['mcp_group'] : null;
                    @endphp
                    <div
                        class="cluster-index-row {{ $mcpExcluded || $seoExcluded ? 'is-excluded' : '' }}"
                        wire:key="cluster-row-{{ $rowKey }}-{{ $this->clusterDataEpoch }}"
                        @if ($isMcpGroup && $canEditCanonical)
                            x-data="{
                                editing: false,
                                recalculating: false,
                                expanded: false,
                                value: @js($rowLabel),
                                original: @js($rowLabel),
                                startEdit() {
                                    if (this.recalculating) return;
                                    this.editing = true;
                                    this.$nextTick(() => this.$refs.input?.focus());
                                },
                                cancel() {
                                    this.value = this.original;
                                    this.editing = false;
                                },
                                async save() {
                                    const next = (this.value || '').trim().replace(/\s+/g, ' ');
                                    if (next === '' || next === this.original) {
                                        this.cancel();
                                        return;
                                    }
                                    this.recalculating = true;
                                    this.editing = false;
                                    try {
                                        const result = await $wire.saveMcpGroupMaskFromIndex(@js($rowKey), next);
                                        if (result && result.ok) {
                                            await $wire.$refresh();
                                            return;
                                        }
                                        this.value = this.original;
                                    } catch (e) {
                                        this.value = this.original;
                                    } finally {
                                        this.recalculating = false;
                                    }
                                }
                            }"
                            :class="{ 'is-recalculating': recalculating }"
                        @elseif ($isMcpGroup)
                            x-data="{ expanded: false }"
                        @elseif ($canEditCanonical)
                            x-data="{
                                editing: false,
                                recalculating: false,
                                value: @js($rowLabel),
                                original: @js($rowLabel),
                                keywordCount: {{ (int) ($row['keyword_count'] ?? 0) }},
                                articleCount: {{ (int) ($row['article_count'] ?? 0) }},
                                internalLinkCount: {{ (int) ($row['internal_link_count'] ?? 0) }},
                                topicalShare: @js($shareDisplay),
                                intent: @js((string) ($row['intent'] ?? '')),
                                coverage: @js((string) ($row['coverage'] ?? 'unknown')),
                                canonicalSource: @js((string) ($row['canonical_source'] ?? 'auto')),
                                state: @js((string) ($row['state'] ?? 'active')),
                                startEdit() {
                                    if (this.recalculating) return;
                                    this.editing = true;
                                    this.$nextTick(() => this.$refs.input?.focus());
                                },
                                cancel() {
                                    this.value = this.original;
                                    this.editing = false;
                                },
                                async save() {
                                    const next = (this.value || '').trim().replace(/\s+/g, ' ');
                                    if (next === '' || next === this.original) {
                                        this.cancel();
                                        return;
                                    }
                                    this.recalculating = true;
                                    this.editing = false;
                                    try {
                                        const result = await $wire.saveClusterCanonicalFromIndex(@js($rowKey), next);
                                        if (result && result.ok) {
                                            await $wire.$refresh();
                                            return;
                                        }
                                        this.value = this.original;
                                    } catch (e) {
                                        this.value = this.original;
                                    } finally {
                                        this.recalculating = false;
                                    }
                                }
                            }"
                            :class="{ 'is-recalculating': recalculating }"
                        @endif
                    >
                        <div class="cluster-index-row__main">
                            <div class="cluster-index-row__title-wrap">
                                @if ($isMcpGroup && $canEditCanonical)
                                    <div
                                        x-show="!editing"
                                        @dblclick.prevent="startEdit()"
                                        class="cluster-index-row__title"
                                        title="{{ __('seo-content-ai::filament.keyword.topic_mcp_group_mask_edit_hint') }}"
                                        x-text="value"
                                    ></div>
                                    <input
                                        x-show="editing"
                                        x-cloak
                                        x-ref="input"
                                        type="text"
                                        class="topic-index-cluster-edit"
                                        x-model="value"
                                        @keydown.enter.prevent.stop="save()"
                                        @keydown.escape.prevent.stop="cancel()"
                                        @blur="if (editing && !recalculating) save()"
                                        :disabled="recalculating"
                                    />
                                    <span class="keyword-item-tag keyword-item-tag--planning">
                                        {{ __('seo-content-ai::filament.keyword.topic_mcp_group_badge', ['count' => $mcpMemberCount]) }}
                                    </span>
                                    <span x-show="recalculating" class="topic-index-recalc">{{ __('seo-content-ai::filament.keyword.topic_canonical_recalculating') }}</span>
                                @elseif ($isMcpGroup)
                                    <div class="cluster-index-row__title">{{ $rowLabel }}</div>
                                    <span class="keyword-item-tag keyword-item-tag--planning">
                                        {{ __('seo-content-ai::filament.keyword.topic_mcp_group_badge', ['count' => $mcpMemberCount]) }}
                                    </span>
                                @elseif ($canEditCanonical)
                                    <div
                                        x-show="!editing"
                                        @dblclick.prevent="startEdit()"
                                        class="cluster-index-row__title"
                                        title="{{ __('seo-content-ai::filament.keyword.topic_canonical_edit_hint') }}"
                                        x-text="value"
                                    ></div>
                                    <input
                                        x-show="editing"
                                        x-cloak
                                        x-ref="input"
                                        type="text"
                                        class="topic-index-cluster-edit"
                                        x-model="value"
                                        @keydown.enter.prevent.stop="save()"
                                        @keydown.escape.prevent.stop="cancel()"
                                        @blur="if (editing && !recalculating) save()"
                                        :disabled="recalculating"
                                    />
                                @else
                                    <div class="cluster-index-row__title">{{ $rowLabel }}</div>
                                @endif

                                @unless ($isMcpGroup)
                                    @include('seo-content-ai::filament.resources.keywords.pages.partials.cluster-intent-coverage-tags', [
                                        'intent' => $row['intent'] ?? '',
                                        'coverage' => $row['coverage'] ?? 'unknown',
                                        'canonicalSource' => $row['canonical_source'] ?? 'auto',
                                        'state' => $row['state'] ?? 'active',
                                        'keywordCount' => $row['keyword_count'] ?? 0,
                                    ])
                                @endunless
                                @if ($seoExcluded)
                                    <span class="keyword-item-tag keyword-item-tag--planning">{{ __('seo-content-ai::filament.keyword.keyword_item_tag_seo_excluded') }}</span>
                                @elseif ($mcpExcluded)
                                    <span class="keyword-item-tag keyword-item-tag--planning">{{ __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_skipped') }}</span>
                                @endif
                                @if (! $isMcpGroup && $mcpGroup)
                                    @if ($canEditCanonical)
                                        <button
                                            type="button"
                                            class="keyword-item-tag keyword-item-tag--planning topic-mcp-group-tag"
                                            wire:click="openMcpGroupModal({{ \Illuminate\Support\Js::from($rowKey) }})"
                                        >{{ __('seo-content-ai::filament.keyword.topic_mcp_group_tag', ['label' => KeywordPhrasePresentation::present((string) ($mcpGroup['mask_name'] ?? ''))]) }}</button>
                                    @else
                                        <span class="keyword-item-tag keyword-item-tag--planning">{{ __('seo-content-ai::filament.keyword.topic_mcp_group_tag', ['label' => KeywordPhrasePresentation::present((string) ($mcpGroup['mask_name'] ?? ''))]) }}</span>
                                    @endif
                                @endif
                            </div>

                            <div class="cluster-index-row__meta">
                                @if ($canEditCanonical && ! $isMcpGroup)
                                    <span x-text="`${keywordCount} {{ __('seo-content-ai::filament.keyword.topic_row_keywords_short') }} · ${articleCount} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }} · ${internalLinkCount} {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}`"></span>
                                    <span x-show="recalculating" class="topic-index-recalc"> · {{ __('seo-content-ai::filament.keyword.topic_canonical_recalculating') }}</span>
                                @else
                                    <span>
                                        {{ number_format((int) $row['keyword_count']) }} {{ __('seo-content-ai::filament.keyword.topic_row_keywords_short') }}
                                        · {{ number_format((int) ($row['article_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                                        · {{ number_format((int) ($row['internal_link_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}
                                    </span>
                                @endif
                            </div>

                            @if ($isMcpGroup && $mcpMembers !== [])
                                <div class="cluster-index-row__mcp-members">
                                    <button
                                        type="button"
                                        class="cluster-index-row__mcp-expand"
                                        @click="expanded = !expanded"
                                        x-text="expanded
                                            ? @js(__('seo-content-ai::filament.keyword.topic_mcp_group_hide_members'))
                                            : @js(__('seo-content-ai::filament.keyword.topic_mcp_group_show_members', ['count' => $mcpMemberCount]))"
                                    ></button>
                                    <ul x-show="expanded" x-cloak class="cluster-index-row__mcp-member-list">
                                        @foreach ($mcpMembers as $member)
                                            <li>
                                                <a href="{{ $this->clusterUrl((string) ($member['cluster_key'] ?? '')) }}">
                                                    {{ KeywordPhrasePresentation::present((string) ($member['label'] ?? $member['cluster_key'] ?? '')) }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <div
                            class="cluster-index-row__share"
                            title="{{ __('seo-content-ai::filament.keyword.topic_mcp_share_tooltip') }}"
                        >
                            @if ($mcpExcluded || $seoExcluded)
                                <span>0%</span>
                            @elseif ($canEditCanonical && ! $isMcpGroup)
                                <span x-text="`${topicalShare}%`">{{ $shareDisplay }}%</span>
                            @else
                                {{ $shareDisplay }}%
                            @endif
                        </div>

                        <div class="cluster-index-row__actions">
                            @unless ($isMcpGroup)
                                <a
                                    href="{{ $this->clusterUrl($rowKey) }}"
                                    class="topic-index-detail-btn"
                                    title="{{ __('seo-content-ai::filament.keyword.topic_view_cluster') }}"
                                    aria-label="{{ __('seo-content-ai::filament.keyword.topic_view_cluster') }}"
                                >
                                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
                                </a>
                            @endunless
                            @if ($canEditCanonical)
                                @include('seo-content-ai::filament.resources.keywords.pages.partials.cluster-row-actions-menu', [
                                    'clusterKey' => $isMcpGroup && $mcpMembers !== []
                                        ? (string) ($mcpMembers[0]['cluster_key'] ?? $rowKey)
                                        : $rowKey,
                                    'mcpExcluded' => $mcpExcluded,
                                    'seoExcluded' => $seoExcluded,
                                    'canDissolve' => $canDissolve && ! $isMcpGroup,
                                    'mcpGrouped' => $isMcpGroup || is_array($mcpGroup),
                                ])
                            @elseif ($canDissolve && ! $isMcpGroup)
                                @include('seo-content-ai::filament.resources.keywords.pages.partials.dissolve-cluster-row-action', [
                                    'clusterKey' => $rowKey,
                                ])
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div>{{ $clusters->links() }}</div>
        @endif

        @include('seo-content-ai::filament.resources.keywords.pages.partials.dissolve-cluster-modal')
        @include('seo-content-ai::filament.resources.keywords.pages.partials.mcp-group-modal')
    </div>
</x-filament-panels::page>
