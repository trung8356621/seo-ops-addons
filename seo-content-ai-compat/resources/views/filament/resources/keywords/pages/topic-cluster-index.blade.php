@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $mcpPreview = $this->getMcpPreviewSummary();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopicClusters();
    $topicMutationsLocked = $reclusterRunning || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicClusterMutationPermission();
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

    @php
        $clusterStateDirty = $this->clusterStateIsDirty();
        $inventoryUnclassified = (int) ($summary['unclassified_keywords'] ?? 0);
        $inventoryMetrics = [
            __('seo-content-ai::filament.keyword.topic_inventory_metric_seo_eligible', [
                'count' => number_format((int) ($summary['seo_eligible_keywords'] ?? 0)),
            ]),
            __('seo-content-ai::filament.keyword.topic_inventory_metric_clustered', [
                'count' => number_format((int) ($summary['clustered'] ?? 0)),
            ]),
            __('seo-content-ai::filament.keyword.topic_inventory_metric_unclustered', [
                'count' => number_format((int) ($summary['unclustered'] ?? 0)),
            ]),
        ];
        if ($inventoryUnclassified > 0) {
            $inventoryMetrics[] = __('seo-content-ai::filament.keyword.topic_inventory_metric_unclassified', [
                'count' => number_format($inventoryUnclassified),
            ]);
        }
        $inventoryMetrics[] = __('seo-content-ai::filament.keyword.topic_inventory_metric_non_seo', [
            'count' => number_format((int) ($summary['non_seo_keywords'] ?? 0)),
        ]);
    @endphp

    <div class="keyword-workspace-shell max-w-full space-y-4" {!! $reclusterPollAttr !!}>
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <header class="topic-index-section-heading">
            <h2 class="topic-index-section-heading__title">
                {{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}
            </h2>
            <p class="topic-index-section-heading__subtitle">
                {{ __('seo-content-ai::filament.keyword.topic_section_subtitle') }}
            </p>
            <p
                class="topic-index-compact-stats"
                wire:key="topic-index-compact-stats-{{ $this->clusterDataEpoch }}"
                title="{{ __('seo-content-ai::filament.keyword.topic_summary_seo_eligible_hint') }} · {{ __('seo-content-ai::filament.keyword.topic_summary_unclustered_hint') }}"
            >
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_seo', [
                    'count' => number_format((int) ($summary['seo_eligible_keywords'] ?? 0)),
                ]) }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_assigned', [
                    'count' => number_format((int) ($summary['clustered'] ?? 0)),
                ]) }}</span>
                <span aria-hidden="true">·</span>
                <a href="{{ $this->unclusteredUrl() }}" class="topic-index-compact-stats__link">
                    {{ __('seo-content-ai::filament.keyword.topic_compact_stats_unassigned', [
                        'count' => number_format((int) ($summary['unclustered'] ?? 0)),
                    ]) }}
                </a>
                <span aria-hidden="true">·</span>
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_mcp', [
                    'count' => number_format((int) ($mcpPreview['total_topics'] ?? 0)),
                ]) }}</span>
            </p>
        </header>

        <x-seo-content-ai::list-table-loading-shell
            class="space-y-4"
            preset="livewire-page"
            targets="clusterSearch,coverageFilter,clusterSort,clusterProjection,hasArticles,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged,applyClusterSearch,clearClusterSearch,updatedCoverageFilter,updatedHasArticles,updatedClusterSort,updatedClusterProjection"
        >
        <div class="topic-index-context" wire:key="topic-index-context-{{ $this->clusterDataEpoch }}">
            <div class="topic-index-context-card">
                <div class="cluster-mcp-preview topic-index-context-card__row">
                    <div class="cluster-mcp-preview__label">{{ __('seo-content-ai::filament.keyword.topic_mcp_preview_label') }}</div>
                    <div class="cluster-mcp-preview__value">
                        {{ __('seo-content-ai::filament.keyword.topic_mcp_preview_line', [
                            'clusters' => number_format((int) $mcpPreview['cluster_count']),
                            'coverage' => rtrim(rtrim(number_format((float) $mcpPreview['coverage_percent'], 1, '.', ''), '0'), '.'),
                            'tokens' => number_format((int) $mcpPreview['estimated_tokens']),
                        ]) }}
                    </div>
                </div>
                <div class="cluster-mcp-preview topic-index-context-card__row" wire:key="cluster-inventory-bar-{{ $this->clusterDataEpoch }}">
                    <div class="cluster-mcp-preview__label">{{ __('seo-content-ai::filament.keyword.topic_inventory_bar_label') }}</div>
                    <div class="cluster-mcp-preview__value">{{ implode(' · ', $inventoryMetrics) }}</div>
                </div>
            </div>

            @if ($clusterStateDirty && $canRecluster)
                <div class="topic-index-stale-alert">
                    <div class="topic-index-stale-alert__body">
                        <div class="topic-index-stale-alert__title">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_recommended_title') }}
                        </div>
                        <p class="topic-index-stale-alert__text">
                            {{ __('seo-content-ai::filament.keyword.topic_cluster_dirty_banner') }}
                        </p>
                    </div>
                    <div class="topic-index-stale-alert__action">
                        @if ($confirmRecluster)
                            <div class="topic-index-stale-alert__confirm">
                                <div class="topic-index-stale-alert__confirm-copy">
                                    <div class="topic-index-stale-alert__confirm-title">
                                        {{ __('seo-content-ai::filament.keyword.topic_recluster_confirm') }}
                                    </div>
                                    <p class="topic-index-stale-alert__confirm-hint">
                                        {{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}
                                    </p>
                                </div>
                                <div class="topic-index-stale-alert__confirm-actions">
                                    <x-filament::button type="button" size="sm" color="gray" wire:click="cancelReclusterConfirm">
                                        {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                                    </x-filament::button>
                                    <x-filament::button type="button" size="sm" color="warning" wire:click="confirmDispatchReclusterTopicClusters">
                                        {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @else
                            <x-filament::button type="button" size="sm" color="warning" wire:click="openReclusterConfirm" :disabled="$reclusterRunning">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @elseif ($clusterStateDirty)
                <div class="topic-index-stale-alert">
                    <div class="topic-index-stale-alert__body">
                        <div class="topic-index-stale-alert__title">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_recommended_title') }}
                        </div>
                        <p class="topic-index-stale-alert__text">
                            {{ __('seo-content-ai::filament.keyword.topic_cluster_dirty_banner') }}
                        </p>
                    </div>
                </div>
            @elseif ($canRecluster)
                <div class="topic-index-recluster-idle">
                    @if ($confirmRecluster)
                        <div class="topic-index-stale-alert__confirm">
                            <div class="topic-index-stale-alert__confirm-copy">
                                <div class="topic-index-stale-alert__confirm-title">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_confirm') }}
                                </div>
                                <p class="topic-index-stale-alert__confirm-hint">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}
                                </p>
                            </div>
                            <div class="topic-index-stale-alert__confirm-actions">
                                <x-filament::button type="button" size="sm" color="gray" wire:click="cancelReclusterConfirm">
                                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                                </x-filament::button>
                                <x-filament::button type="button" size="sm" color="warning" wire:click="confirmDispatchReclusterTopicClusters">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                </x-filament::button>
                            </div>
                        </div>
                    @else
                        <div class="topic-index-recluster-idle__row">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="openReclusterConfirm" :disabled="$reclusterRunning">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                            </x-filament::button>
                            <span
                                class="inline-flex text-gray-400 dark:text-gray-500"
                                title="{{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}"
                                aria-label="{{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}"
                            >
                                <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-4 w-4" />
                            </span>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div
            class="topic-index-toolbar"
            x-data="{ phrase: @entangle('clusterSearchInput'), mutationsLocked: @js($topicMutationsLocked) }"
        >
            <div class="topic-index-toolbar__primary">
                <form wire:submit="applyClusterSearch" class="contents">
                    <input
                        type="search"
                        x-model="phrase"
                        class="topic-index-input topic-index-input--search"
                        placeholder="{{ __('seo-content-ai::filament.keyword.topic_search_or_create_cluster') }}"
                        autocomplete="off"
                    >
                </form>
                @if ($canEditPermission)
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="primary"
                        wire:click="quickCreateCluster"
                        wire:loading.attr="disabled"
                        wire:target="quickCreateCluster"
                        x-bind:disabled="!String(phrase || '').trim() || mutationsLocked"
                        :disabled="$topicMutationsLocked"
                        :title="$topicMutationsLocked ? __('seo-content-ai::filament.keyword.topic_recluster_mutations_locked') : null"
                    >
                        <span wire:loading.remove wire:target="quickCreateCluster">
                            {{ __('seo-content-ai::filament.keyword.topic_quick_create_action') }}
                        </span>
                        <span wire:loading wire:target="quickCreateCluster" class="inline-flex items-center gap-1">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            {{ __('seo-content-ai::filament.keyword.topic_quick_create_action') }}
                        </span>
                    </x-filament::button>
                @endif
            </div>
            <div class="topic-index-filters topic-index-toolbar__filters">
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

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterRunning || $topicMutationsLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_title') }}</div>
                <p class="mt-1 opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_body') }}</p>
                @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running')
                    <p class="mt-1 opacity-75">{{ __('seo-content-ai::filament.keyword.topic_recluster_running') }}</p>
                @endif
            </div>
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
                        $planningPending = (int) ($row['planning_pending_count'] ?? 0);
                        $mcpExcluded = (bool) ($row['mcp_excluded'] ?? false);
                        $seoExcluded = (bool) ($row['seo_excluded'] ?? false);
                        $isMcpGroup = (bool) ($row['is_mcp_group'] ?? false);
                        $mcpMembers = is_array($row['mcp_members'] ?? null) ? $row['mcp_members'] : [];
                        $mcpMemberCount = (int) ($row['mcp_member_count'] ?? count($mcpMembers));
                        $mcpGroup = is_array($row['mcp_group'] ?? null) ? $row['mcp_group'] : null;
                    @endphp
                    <div
                        class="cluster-index-row {{ $mcpExcluded || $seoExcluded ? 'is-excluded' : '' }}"
                        data-cluster-key="{{ $rowKey }}"
                        wire:key="cluster-row-{{ $rowKey }}"
                        @if ($isMcpGroup && $canEditCanonical)
                            x-data="{
                                editing: false,
                                recalculating: false,
                                expanded: false,
                                value: @js($rowLabel),
                                original: @js($rowLabel),
                                renameSeq: 0,
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
                                    const seq = ++this.renameSeq;
                                    const previousTitle = this.original;
                                    this.value = next;
                                    this.recalculating = true;
                                    this.editing = false;
                                    try {
                                        const result = await $wire.saveMcpGroupMaskFromIndex(@js($rowKey), next);
                                        if (seq !== this.renameSeq) {
                                            return;
                                        }
                                        if (result && result.ok) {
                                            this.value = next;
                                            this.original = next;
                                            return;
                                        }
                                        this.value = previousTitle;
                                        this.original = previousTitle;
                                    } catch (e) {
                                        if (seq !== this.renameSeq) {
                                            return;
                                        }
                                        this.value = previousTitle;
                                        this.original = previousTitle;
                                    } finally {
                                        if (seq === this.renameSeq) {
                                            this.recalculating = false;
                                        }
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
                                renameSeq: 0,
                                coverageTagTemplate: @js(__('seo-content-ai::filament.keyword.topic_tag_coverage', ['level' => '__LEVEL__'])),
                                plannedLabel: @js(__('seo-content-ai::filament.keyword.topic_tag_planned')),
                                manualLabel: @js(__('seo-content-ai::filament.keyword.topic_tag_manual')),
                                autoLabel: @js(__('seo-content-ai::filament.keyword.topic_tag_auto')),
                                startEdit() {
                                    if (this.recalculating) return;
                                    this.editing = true;
                                    this.$nextTick(() => this.$refs.input?.focus());
                                },
                                cancel() {
                                    this.value = this.original;
                                    this.editing = false;
                                },
                                formatToken(value) {
                                    const raw = String(value || '').trim();
                                    if (raw === '') return '';
                                    return raw.charAt(0).toUpperCase() + raw.slice(1);
                                },
                                coverageTagText() {
                                    const level = this.formatToken(this.coverage);
                                    if (level === '') return '';
                                    return String(this.coverageTagTemplate || '').replace('__LEVEL__', level);
                                },
                                formatShare(share) {
                                    const num = Number(share);
                                    if (! Number.isFinite(num)) return '0';
                                    return String(num.toFixed(1)).replace(/\.0$/, '').replace(/(\.\d)0$/, '$1');
                                },
                                applyRowPatch(result) {
                                    if (! result || typeof result !== 'object') return;
                                    if (result.keyword_count !== undefined) this.keywordCount = Number(result.keyword_count) || 0;
                                    if (result.article_count !== undefined) this.articleCount = Number(result.article_count) || 0;
                                    if (result.internal_link_count !== undefined) this.internalLinkCount = Number(result.internal_link_count) || 0;
                                    if (result.intent !== undefined) this.intent = String(result.intent || '');
                                    if (result.coverage !== undefined) this.coverage = String(result.coverage || 'unknown');
                                    if (result.canonical_source !== undefined) this.canonicalSource = String(result.canonical_source || 'manual');
                                    if (result.state !== undefined) this.state = String(result.state || 'active');
                                    if (result.topical_share !== undefined) this.topicalShare = this.formatShare(result.topical_share);
                                },
                                async save() {
                                    const next = (this.value || '').trim().replace(/\s+/g, ' ');
                                    if (next === '' || next === this.original) {
                                        this.cancel();
                                        return;
                                    }
                                    const seq = ++this.renameSeq;
                                    const previousTitle = this.original;
                                    this.value = next;
                                    this.recalculating = true;
                                    this.editing = false;
                                    try {
                                        const result = await $wire.saveClusterCanonicalFromIndex(@js($rowKey), next);
                                        if (seq !== this.renameSeq) {
                                            return;
                                        }
                                        if (result && result.ok) {
                                            if (result.removed) {
                                                this.$el.remove();
                                                return;
                                            }
                                            this.applyRowPatch(result);
                                            this.value = next;
                                            this.original = next;
                                            return;
                                        }
                                        this.value = previousTitle;
                                        this.original = previousTitle;
                                    } catch (e) {
                                        if (seq !== this.renameSeq) {
                                            return;
                                        }
                                        this.value = previousTitle;
                                        this.original = previousTitle;
                                    } finally {
                                        if (seq === this.renameSeq) {
                                            this.recalculating = false;
                                        }
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
                                    @if ($canEditCanonical)
                                        <div class="cluster-tag-row">
                                            <span
                                                class="cluster-tag cluster-tag--intent"
                                                x-show="intent"
                                                x-cloak
                                                x-text="formatToken(intent)"
                                            ></span>
                                            <span
                                                class="cluster-tag cluster-tag--coverage"
                                                x-show="coverage && coverage !== 'unknown'"
                                                x-cloak
                                                x-text="coverageTagText()"
                                            ></span>
                                            <span
                                                class="cluster-tag cluster-tag--planned"
                                                x-show="state === 'planned' || (Number(keywordCount) === 0 && canonicalSource === 'manual')"
                                                x-cloak
                                                x-text="plannedLabel"
                                            ></span>
                                            <span
                                                class="cluster-tag cluster-tag--manual"
                                                x-show="canonicalSource === 'manual'"
                                                x-cloak
                                                x-text="manualLabel"
                                            ></span>
                                            <span
                                                class="cluster-tag cluster-tag--auto"
                                                x-show="canonicalSource === 'auto' && state !== 'planned'"
                                                x-cloak
                                                x-text="autoLabel"
                                            ></span>
                                        </div>
                                    @else
                                        @include('seo-content-ai::filament.resources.keywords.pages.partials.cluster-intent-coverage-tags', [
                                            'intent' => $row['intent'] ?? '',
                                            'coverage' => $row['coverage'] ?? 'unknown',
                                            'canonicalSource' => $row['canonical_source'] ?? 'auto',
                                            'state' => $row['state'] ?? 'active',
                                            'keywordCount' => $row['keyword_count'] ?? 0,
                                        ])
                                    @endif
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
                                            @click="$dispatch('mcp-group-modal-open', { clusterKey: {{ \Illuminate\Support\Js::from($rowKey) }} })"
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
                            @if ($planningPending > 0)
                                <span
                                    class="cluster-index-row__planning-plus"
                                    title="{{ __('seo-content-ai::filament.keyword.topic_mcp_planning_pending_tooltip', ['count' => $planningPending]) }}"
                                >+{{ $planningPending }}</span>
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
            <div wire:key="topic-cluster-pagination-{{ $clusters->currentPage() }}-{{ $this->clusterDataEpoch }}">
                {{ $clusters->links() }}
            </div>
        @endif
        </x-seo-content-ai::list-table-loading-shell>

        @include('seo-content-ai::filament.resources.keywords.pages.partials.mcp-group-modal')
    </div>
</x-filament-panels::page>
