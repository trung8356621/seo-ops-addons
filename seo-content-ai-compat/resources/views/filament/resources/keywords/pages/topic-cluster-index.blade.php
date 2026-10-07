@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $showReclusterModal = (bool) ($this->showReclusterModal ?? false);
    $reclusterModalStep = (string) ($this->reclusterModalStep ?? 'configure');
    $canRecluster = $this->canReclusterTopics();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterActive = $reclusterRunning
        || $reclusterStatus === 'queued'
        || $reclusterStatus === 'running'
        || $reclusterStatus === 'analyzing'
        || $reclusterStatus === 'applying';
    $topicMutationsLocked = $reclusterActive || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicClusterMutationPermission();
    $canDissolve = $this->canDissolveCluster();
    $canEditCanonical = $this->canEditClusterCanonical();
    $reclusterPollAttr = ($reclusterActive || ($showReclusterModal && in_array($reclusterModalStep, ['analyzing', 'applying'], true)))
        ? 'wire:poll.5s="pollReclusterResult"'
        : '';
    $confirmApplyProposal = (bool) ($this->confirmApplyProposal ?? false);
    $proposalPreview = is_array($this->proposalPreview ?? null) ? $this->proposalPreview : null;
    $previewCounts = is_array($proposalPreview['counts'] ?? null) ? $proposalPreview['counts'] : [];
    $identityMigration = is_array($proposalPreview['identity_migration'] ?? null) ? $proposalPreview['identity_migration'] : [];
    $businessState = is_array($proposalPreview['business_state'] ?? null) ? $proposalPreview['business_state'] : [];
    $businessSummary = is_array($businessState['summary'] ?? null) ? $businessState['summary'] : [];
    $businessHardBlock = (bool) ($businessState['hard_block'] ?? false);
    $canFullResetRebuild = (bool) $this->canUseFullResetRebuildMode();
    $runRebuildMode = (string) $this->persistedRebuildMode();
    $isFullResetRun = $runRebuildMode === 'full_reset';
    $reclusterMetrics = is_array($this->reclusterResult['metrics'] ?? null) ? $this->reclusterResult['metrics'] : [];
    $pendingProposalBanner = in_array($reclusterStatus, ['proposal_ready', 'apply_failed', 'stale', 'queued', 'analyzing', 'applying'], true);

    $assignedCount = (int) ($summary['assigned'] ?? $summary['clustered'] ?? 0);
    $unassignedCount = (int) ($summary['unassigned'] ?? $summary['unclustered'] ?? 0);
    $topicCount = (int) ($summary['topic_count'] ?? 0);
    $seoEligibleCount = (int) ($summary['seo_eligible_keywords'] ?? 0);
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-cluster-index-page max-w-full" wire:init="loadAiAuditSnapshot">
    <x-seo-content-ai::content-project-ops-styles />
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    @php
        $clusterStateDirty = $this->clusterStateIsDirty();
        $inventoryMetrics = [
            __('seo-content-ai::filament.keyword.topic_inventory_metric_seo_eligible', [
                'count' => number_format($seoEligibleCount),
            ]),
            __('seo-content-ai::filament.keyword.topic_inventory_metric_clustered', [
                'count' => number_format($assignedCount),
            ]),
            __('seo-content-ai::filament.keyword.topic_inventory_metric_unclustered', [
                'count' => number_format($unassignedCount),
            ]),
        ];
    @endphp

    <div class="keyword-workspace-shell max-w-full space-y-4" {!! $reclusterPollAttr !!}>
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <header class="topic-index-section-heading">
            <div class="topic-index-section-heading__row">
                <h2 class="topic-index-section-heading__title">
                    {{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}
                </h2>
                @php $topicalMapUrl = $this->getTopicalMapUrl(); @endphp
                @if (is_string($topicalMapUrl) && $topicalMapUrl !== '')
                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="gray"
                        :href="$topicalMapUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="topic-index-section-heading__map-link"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            {{ __('seo-content-ai::filament.keyword.workspace_nav_topical_map') }}
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
                        </span>
                    </x-filament::button>
                @endif
            </div>
            <p class="topic-index-section-heading__subtitle">
                {{ __('seo-content-ai::filament.keyword.topic_section_subtitle') }}
            </p>
            <p
                class="topic-index-compact-stats"
                wire:key="topic-index-compact-stats-{{ $this->clusterDataEpoch }}"
                title="{{ __('seo-content-ai::filament.keyword.topic_summary_seo_eligible_hint') }} · {{ __('seo-content-ai::filament.keyword.topic_summary_unclustered_hint') }}"
            >
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_seo', [
                    'count' => number_format($seoEligibleCount),
                ]) }}</span>
                <span aria-hidden="true">·</span>
                <a href="{{ $this->assignedUrl() }}" class="topic-index-compact-stats__link">
                    {{ __('seo-content-ai::filament.keyword.topic_compact_stats_assigned', [
                        'count' => number_format($assignedCount),
                    ]) }}
                </a>
                <span aria-hidden="true">·</span>
                <a href="{{ $this->unassignedUrl() }}" class="topic-index-compact-stats__link">
                    {{ __('seo-content-ai::filament.keyword.topic_compact_stats_unassigned', [
                        'count' => number_format($unassignedCount),
                    ]) }}
                </a>
                <span aria-hidden="true">·</span>
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_topics', [
                    'count' => number_format($topicCount),
                ]) }}</span>
            </p>
        </header>

        <x-seo-content-ai::list-table-loading-shell
            class="space-y-4"
            preset="livewire-page"
            targets="clusterSearch,lockFilter,clusterSort,hasArticles,topicTags,intentFilter,coverageFilter,sourceFilter,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged,applyClusterSearch,clearClusterSearch,updatedLockFilter,updatedHasArticles,updatedClusterSort,updatedTopicTags,updatedIntentFilter,updatedCoverageFilter,updatedSourceFilter,setTopicTagFilterIds,toggleTopicTagFilter,clearTopicTagFilters,quickCreateTopic"
        >
        <div class="topic-index-context" wire:key="topic-index-context-{{ $this->clusterDataEpoch }}">
            <div class="topic-index-context-card">
                <div class="cluster-mcp-preview topic-index-context-card__row">
                    <div class="cluster-mcp-preview__label">{{ __('seo-content-ai::filament.keyword.topic_mcp_preview_label') }}</div>
                    <div class="cluster-mcp-preview__value">
                        {{ __('seo-content-ai::filament.keyword.topic_compact_stats_topics', [
                            'count' => number_format($topicCount),
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
                        <div class="topic-index-stale-alert__confirm-actions" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                            <x-filament::button type="button" size="sm" color="warning" wire:click="openReclusterModal" :disabled="$topicMutationsLocked && ! $pendingProposalBanner">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                            </x-filament::button>
                            @php $aiHistoryUrlDirty = $this->aiHistoryUrl(); @endphp
                            @if (is_string($aiHistoryUrlDirty) && $aiHistoryUrlDirty !== '')
                                <x-filament::button
                                    tag="a"
                                    size="sm"
                                    color="gray"
                                    :href="$aiHistoryUrlDirty"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ __('seo-content-ai::filament.keyword.topic_ai_history_link') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="primary"
                                wire:click="beginConfirmAiAudit"
                                :disabled="! $this->canRunAiAuditAndTags() || $reclusterActive || $topicMutationsLocked || $this->aiAuditRunning"
                            >
                                {{ $this->aiAuditButtonLabel() }}
                            </x-filament::button>
                        </div>
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
                    <div class="topic-index-recluster-idle__row" style="flex-wrap:wrap;align-items:center;gap:0.5rem;">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="openReclusterModal" :disabled="$topicMutationsLocked && ! $pendingProposalBanner">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                        </x-filament::button>
                        @php
                            $aiAuditCan = $this->canRunAiAuditAndTags();
                            $aiAuditLabel = $this->aiAuditButtonLabel();
                            $aiHistoryUrl = $this->aiHistoryUrl();
                        @endphp
                        @if (is_string($aiHistoryUrl) && $aiHistoryUrl !== '')
                            <x-filament::button
                                tag="a"
                                size="sm"
                                color="gray"
                                :href="$aiHistoryUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ __('seo-content-ai::filament.keyword.topic_ai_history_link') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="primary"
                            wire:click="beginConfirmAiAudit"
                            wire:loading.attr="disabled"
                            wire:target="confirmRunAiAuditAndTags,beginConfirmAiAudit"
                            :disabled="! $aiAuditCan || $reclusterActive || $topicMutationsLocked || $this->aiAuditRunning"
                        >
                            <span wire:loading.remove wire:target="confirmRunAiAuditAndTags">
                                {{ $aiAuditLabel }}
                            </span>
                            <span wire:loading wire:target="confirmRunAiAuditAndTags" class="inline-flex items-center gap-2">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.ai_audit_tags_running') }}
                            </span>
                        </x-filament::button>
                        <span
                            class="inline-flex text-gray-400 dark:text-gray-500"
                            title="{{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}"
                            aria-label="{{ __('seo-content-ai::filament.keyword.topic_recluster_hint') }}"
                        >
                            <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-4 w-4" />
                        </span>
                    </div>
                </div>
            @endif
        </div>

        @if ($this->confirmAiAudit)
            @php $snap = $this->aiAuditStatusSnapshot(); @endphp
            <div class="topic-ai-audit-modal" role="dialog" aria-modal="true" aria-labelledby="topic-ai-audit-modal-title">
                <div class="topic-ai-audit-modal__backdrop" wire:click="cancelConfirmAiAudit"></div>
                <div class="topic-ai-audit-modal__panel">
                    <h3 id="topic-ai-audit-modal-title" class="topic-ai-audit-modal__title">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_modal_title') }}
                    </h3>
                    <p class="topic-ai-audit-modal__site">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_site') }}:
                        <strong>{{ $snap['site_domain'] !== '' ? $snap['site_domain'] : ('#'.$snap['site_id']) }}</strong>
                    </p>
                    <ul class="topic-ai-audit-modal__stats">
                        <li>{{ number_format((int) $snap['topic_count']) }} Topics</li>
                        <li>{{ number_format((int) $snap['assigned_keywords']) }} {{ __('seo-content-ai::filament.keyword.ai_audit_tags_assigned_kw') }}</li>
                        <li>{{ number_format((int) $snap['unassigned_keywords']) }} {{ __('seo-content-ai::filament.keyword.ai_audit_tags_unassigned_kw') }}</li>
                        <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_existing') }}: {{ number_format((int) $snap['existing_tags']) }}</li>
                        <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_topics_tagged') }}: {{ number_format((int) $snap['topics_with_tags']) }} / {{ number_format((int) $snap['topic_count']) }}</li>
                        <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_untagged') }}: {{ number_format((int) $snap['untagged_topics']) }}</li>
                    </ul>
                    <p class="topic-ai-audit-modal__meta">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_last_run') }}:
                        @if (! empty($snap['last_ai_run_at']))
                            {{ \Illuminate\Support\Carbon::parse($snap['last_ai_run_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        @else
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_never') }}
                        @endif
                    </p>
                    <p class="topic-ai-audit-modal__meta">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_status_label') }}:
                        @if (($snap['status'] ?? '') === 'current')
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_status_current') }}
                        @elseif (($snap['status'] ?? '') === 'stale')
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_status_stale') }}
                        @else
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_status_never') }}
                        @endif
                    </p>
                    <p class="topic-ai-audit-modal__notice">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_cost_notice') }}
                    </p>
                    <div class="topic-ai-audit-modal__actions">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="cancelConfirmAiAudit">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_cancel') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="sm" color="primary" wire:click="confirmRunAiAuditAndTags">
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_run') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        @php
            $latestAi = $this->latestAiAudit();
            $aiStatus = (string) ($latestAi['status'] ?? 'never_run');
            $aiSummaryText = trim((string) ($latestAi['summary'] ?? ''));
            $aiSiteNotes = $this->aiSiteWideFindings();
            $aiHistoryLink = $this->aiHistoryUrl();
            $hasAiAuditUi = $aiStatus !== 'never_run'
                || $aiSummaryText !== ''
                || ($latestAi['findings_count'] ?? 0) > 0
                || $aiSiteNotes !== [];
        @endphp

        @if ($hasAiAuditUi)
            <details
                class="topic-ai-audit-summary"
                wire:key="topic-ai-audit-summary-{{ $this->clusterDataEpoch }}-{{ (int) ($latestAi['last_prompt_result_id'] ?? 0) }}"
            >
                <summary class="topic-ai-audit-summary__header">
                    <span class="topic-ai-audit-summary__title">
                        {{ __('seo-content-ai::filament.keyword.topic_ai_audit_summary_title') }}
                        <span aria-hidden="true">·</span>
                        @if ($aiStatus === 'current')
                            {{ __('seo-content-ai::filament.keyword.topic_ai_audit_status_current') }}
                        @elseif ($aiStatus === 'stale')
                            {{ __('seo-content-ai::filament.keyword.topic_ai_audit_status_stale') }}
                        @else
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_status_never') }}
                        @endif
                        @if (! empty($latestAi['last_run_at']))
                            <span aria-hidden="true">·</span>
                            {{ \Illuminate\Support\Carbon::parse($latestAi['last_run_at'])->timezone(config('app.timezone'))->format('d/m/Y') }}
                        @endif
                    </span>
                    @if ($aiStatus === 'stale')
                        <span class="topic-ai-audit-summary__stale-pill">
                            {{ __('seo-content-ai::filament.keyword.topic_ai_audit_stale_badge') }}
                        </span>
                    @endif
                </summary>
                <div class="topic-ai-audit-summary__body">
                    @if ($aiSummaryText !== '')
                        <p class="topic-ai-audit-summary__quote">“{{ $aiSummaryText }}”</p>
                    @endif
                    <p class="topic-ai-audit-summary__counts">
                        {{ __('seo-content-ai::filament.keyword.topic_ai_audit_counts', [
                            'findings' => (int) ($latestAi['findings_count'] ?? 0),
                            'opportunities' => (int) ($latestAi['opportunities_count'] ?? 0),
                            'actions' => (int) ($latestAi['actions_count'] ?? 0),
                        ]) }}
                    </p>
                    @if (is_string($aiHistoryLink) && $aiHistoryLink !== '')
                        <a
                            href="{{ $aiHistoryLink }}"
                            class="topic-ai-audit-summary__history"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_ai_history_link') }}
                        </a>
                    @endif
                </div>
            </details>
        @endif

        @if ($aiSiteNotes !== [])
            <div
                class="topic-ai-site-notes"
                wire:key="topic-ai-site-notes-{{ $this->clusterDataEpoch }}"
                x-data="{ open: false, limit: 3 }"
            >
                <div class="topic-ai-site-notes__title">
                    {{ __('seo-content-ai::filament.keyword.topic_ai_site_notes_title') }}
                </div>
                <ul class="topic-ai-site-notes__list">
                    @foreach ($aiSiteNotes as $noteIdx => $siteFinding)
                        @php
                            $noteText = trim((string) ($siteFinding['observation'] ?? ''));
                            if ($noteText === '') {
                                $noteText = trim((string) ($siteFinding['title'] ?? ''));
                            }
                        @endphp
                        <li
                            class="topic-ai-site-notes__item"
                            x-show="open || {{ $noteIdx }} < limit"
                            @if ($noteIdx >= 3) x-cloak @endif
                        >
                            <span aria-hidden="true">⚠</span>
                            {{ $noteText }}
                        </li>
                    @endforeach
                </ul>
                @if (count($aiSiteNotes) > 3)
                    <button
                        type="button"
                        class="topic-ai-site-notes__more"
                        x-show="!open"
                        @click="open = true"
                    >
                        {{ __('seo-content-ai::filament.keyword.topic_ai_site_notes_more') }}
                    </button>
                @endif
            </div>
        @endif

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
                        wire:click="quickCreateTopic"
                        wire:loading.attr="disabled"
                        wire:target="quickCreateTopic"
                        :disabled="$topicMutationsLocked"
                        x-bind:disabled="mutationsLocked || !(phrase || '').trim()"
                    >
                        <span wire:loading.remove wire:target="quickCreateTopic">
                            {{ __('seo-content-ai::filament.keyword.topic_quick_create_action') }}
                        </span>
                        <span wire:loading wire:target="quickCreateTopic" class="inline-flex items-center gap-1.5">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            {{ __('seo-content-ai::filament.keyword.topic_quick_create_action') }}
                        </span>
                    </x-filament::button>
                @endif
            </div>
            <div class="topic-index-filters topic-index-toolbar__filters">
                <x-select size="sm" wire:model.live="lockFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_all') }}</option>
                    <option value="topic_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_topic') }}</option>
                    <option value="membership_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_membership') }}</option>
                    <option value="unlocked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_unlocked') }}</option>
                </x-select>
                <x-select size="sm" wire:model.live="intentFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_filter_intent_all') }}</option>
                    <option value="commercial">{{ __('seo-content-ai::filament.keyword.intent_commercial') }}</option>
                    <option value="informational">{{ __('seo-content-ai::filament.keyword.intent_informational') }}</option>
                </x-select>
                <x-select size="sm" wire:model.live="coverageFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_filter_coverage_all') }}</option>
                    <option value="strong">{{ __('seo-content-ai::filament.keyword.topic_shortcut_coverage_strong') }}</option>
                    <option value="medium">{{ __('seo-content-ai::filament.keyword.topic_shortcut_coverage_medium') }}</option>
                    <option value="weak">{{ __('seo-content-ai::filament.keyword.topic_shortcut_coverage_weak') }}</option>
                </x-select>
                <x-select size="sm" wire:model.live="sourceFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_filter_source_all') }}</option>
                    <option value="auto">{{ __('seo-content-ai::filament.keyword.topic_tag_auto') }}</option>
                    <option value="manual">{{ __('seo-content-ai::filament.keyword.topic_tag_manual') }}</option>
                </x-select>
                <div
                    class="relative min-w-[14rem]"
                    x-data="{
                        open: false,
                        q: '',
                        selected: @js($this->getSelectedTopicTagChips()),
                        results: [],
                        async refresh() {
                            try {
                                this.results = await $wire.searchTopicTags(this.q || '');
                            } catch (e) {
                                this.results = [];
                            }
                        },
                        isSelected(id) {
                            return (this.selected || []).some((t) => Number(t.id) === Number(id));
                        },
                        async toggle(tag) {
                            await $wire.toggleTopicTagFilter(Number(tag.id));
                            const id = Number(tag.id);
                            if (this.isSelected(id)) {
                                this.selected = (this.selected || []).filter((t) => Number(t.id) !== id);
                            } else {
                                this.selected = [...(this.selected || []), { id, name: tag.name }];
                            }
                        },
                        async clearAll() {
                            await $wire.clearTopicTagFilters();
                            this.selected = [];
                            this.q = '';
                            this.open = false;
                        },
                    }"
                    @click.outside="open = false"
                >
                    <button
                        type="button"
                        class="topic-index-cluster-edit flex w-full items-center justify-between gap-2 text-left"
                        @click="open = !open; if (open) refresh()"
                    >
                        <span class="truncate text-xs" x-text="(selected || []).length ? selected.map(t => t.name).join(', ') : @js(__('seo-content-ai::filament.keyword.topic_tag_filter_all'))"></span>
                        <span class="opacity-60">▾</span>
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        class="absolute z-30 mt-1 w-72 rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-gray-900"
                    >
                        <input
                            type="search"
                            class="topic-index-cluster-edit mb-2 w-full"
                            x-model="q"
                            @input.debounce.200ms="refresh()"
                            placeholder="{{ __('seo-content-ai::filament.keyword.topic_tags_search_placeholder') }}"
                        />
                        <div class="mb-2 flex flex-wrap gap-1" x-show="(selected || []).length">
                            <template x-for="chip in selected" :key="'sel-' + chip.id">
                                <button
                                    type="button"
                                    class="cluster-tag cluster-tag--manual"
                                    @click.stop="toggle(chip)"
                                >
                                    <span x-text="chip.name"></span>
                                    <span class="ml-1">×</span>
                                </button>
                            </template>
                            <button type="button" class="text-xs text-gray-500 hover:underline" @click.stop="clearAll()">
                                {{ __('seo-content-ai::filament.keyword.topic_tag_filter_clear') }}
                            </button>
                        </div>
                        <div class="max-h-48 space-y-1 overflow-y-auto">
                            <template x-for="opt in results" :key="'opt-' + opt.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded px-2 py-1 text-left text-xs hover:bg-gray-50 dark:hover:bg-white/5"
                                    @click.stop="toggle(opt)"
                                >
                                    <span x-text="opt.name"></span>
                                    <span x-show="isSelected(opt.id)">✓</span>
                                </button>
                            </template>
                            <div class="px-2 py-2 text-xs text-gray-400" x-show="!(results || []).length">
                                {{ __('seo-content-ai::filament.keyword.topic_tags_empty') }}
                            </div>
                        </div>
                        <p class="mt-2 px-1 text-[10px] text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.topic_tag_filter_and_hint') }}
                        </p>
                    </div>
                </div>
                <x-select size="sm" wire:model.live="clusterSort">
                    <option value="topical_share_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_topical_share_desc') }}</option>
                    <option value="topical_share_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_topical_share_asc') }}</option>
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

        @if ($pendingProposalBanner && ! $showReclusterModal)
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100 flex flex-wrap items-center justify-between gap-2">
                <div>
                    @if (in_array($reclusterStatus, ['queued', 'analyzing', 'running', 'applying'], true))
                        <div class="font-medium">Đang xử lý tách lại chủ đề…</div>
                    @elseif ($reclusterStatus === 'failed')
                        <div class="font-medium">Phân tích tách lại chủ đề thất bại</div>
                    @else
                        <div class="font-medium">Có đề xuất tách lại chủ đề đang chờ xử lý</div>
                        <p class="mt-0.5 opacity-90">
                            Mode: {{ $isFullResetRun ? 'Tách lại hoàn toàn' : 'Giữ cấu trúc hiện tại' }}
                            · {{ number_format((int) ($reclusterMetrics['group_count'] ?? 0)) }} nhóm
                        </p>
                    @endif
                </div>
                <x-filament::button type="button" size="xs" color="primary" wire:click="openReclusterModal">
                    Mở
                </x-filament::button>
            </div>
        @endif

        @if ($showReclusterModal)
            @php
                $modeLabel = $isFullResetRun ? 'Tách lại hoàn toàn' : 'Giữ cấu trúc hiện tại';
                $membershipRebuild = (int) (($previewCounts['keywords_moved'] ?? 0) + ($previewCounts['keywords_assigned'] ?? 0));
            @endphp
            <div class="topic-ai-audit-modal" role="dialog" aria-modal="true" aria-labelledby="topic-recluster-modal-title">
                <div class="topic-ai-audit-modal__backdrop" wire:click="closeReclusterModal"></div>
                <div class="topic-ai-audit-modal__panel" style="max-width:36rem;max-height:85vh;overflow:auto;">
                    <h3 id="topic-recluster-modal-title" class="topic-ai-audit-modal__title">
                        Tách lại chủ đề
                    </h3>

                    @if ($reclusterModalStep === 'configure')
                        @if ($canFullResetRebuild)
                            <label class="mt-3 flex items-start gap-2 text-sm text-rose-800 dark:text-rose-200">
                                <input type="checkbox" class="mt-1" wire:model.live="fullResetTopicStructure">
                                <span>
                                    <span class="font-medium">Xóa cấu trúc Topic cũ và tách lại từ đầu</span>
                                    <span class="mt-1 block text-xs opacity-90">
                                        Khi bật, cấu trúc Topic hiện tại chỉ bị thay thế sau khi bạn xem đề xuất và bấm Apply.
                                        Keywords, Articles và Focus Article được giữ nguyên.
                                    </span>
                                    @if ($this->fullResetTopicStructure)
                                        <span class="mt-2 block text-xs">
                                            · Topic cũ sẽ không được dùng làm identity khi Apply<br>
                                            · Keywords / Articles / Focus Article được giữ
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @else
                            <p class="topic-ai-audit-modal__notice mt-3">
                                Provider hiện tại: legacy — tách lại theo thuật toán cũ (không có full reset).
                            </p>
                        @endif
                        <div class="topic-ai-audit-modal__actions mt-4">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="closeReclusterModal">Hủy</x-filament::button>
                            <x-filament::button type="button" size="sm" color="{{ ($canFullResetRebuild && $this->fullResetTopicStructure) ? 'danger' : 'warning' }}" wire:click="startTopicAnalysis" wire:loading.attr="disabled">
                                Phân tích
                            </x-filament::button>
                        </div>
                    @elseif ($reclusterModalStep === 'analyzing')
                        <p class="mt-3 text-sm font-medium">Đang phân tích từ khóa…</p>
                        <p class="mt-1 text-xs">Mode: {{ $this->fullResetTopicStructure && $canFullResetRebuild ? 'Tách lại hoàn toàn' : ($isFullResetRun ? 'Tách lại hoàn toàn' : 'Giữ cấu trúc hiện tại') }}</p>
                        <p class="mt-2 text-xs opacity-80">Analyze không xóa Topic. Có thể đóng modal và mở lại sau.</p>
                        <div class="topic-ai-audit-modal__actions mt-4">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="closeReclusterModal">Đóng</x-filament::button>
                        </div>
                    @elseif ($reclusterModalStep === 'proposal_ready')
                        <p class="mt-3 text-sm font-semibold">Phân tích hoàn tất</p>
                        <p class="mt-1 text-xs font-medium {{ $isFullResetRun ? 'text-rose-700' : '' }}">Mode: {{ $modeLabel }}</p>
                        @if ($isFullResetRun)
                            <p class="mt-1 text-xs text-rose-700 font-semibold">Apply sẽ thay thế toàn bộ cấu trúc Topic hiện tại.</p>
                        @endif
                        <ul class="topic-ai-audit-modal__stats mt-2">
                            <li>{{ number_format((int) ($reclusterMetrics['group_count'] ?? 0)) }} nhóm</li>
                            <li>{{ number_format((int) ($reclusterMetrics['unassigned_count'] ?? 0)) }} chưa gán</li>
                            <li>{{ number_format((int) ($reclusterMetrics['low_confidence_count'] ?? 0)) }} low confidence</li>
                        </ul>
                        <div class="topic-ai-audit-modal__actions mt-4">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="discardProposal"
                                wire:confirm="Discard proposal? Topic business state sẽ không đổi.">
                                Discard
                            </x-filament::button>
                            <x-filament::button type="button" size="sm" color="primary" wire:click="openProposalPreview" wire:loading.attr="disabled"
                                wire:target="openProposalPreview">
                                Xem thay đổi
                            </x-filament::button>
                        </div>
                    @elseif (in_array($reclusterModalStep, ['preview', 'confirm_apply'], true) && $proposalPreview)
                        <p class="mt-2 text-sm font-semibold {{ $isFullResetRun ? 'text-rose-700' : '' }}">
                            {{ $isFullResetRun ? 'Tách lại hoàn toàn' : 'Giữ cấu trúc hiện tại' }}
                        </p>
                        <p class="mt-1 text-[11px] text-gray-500">run #{{ (int) ($proposalPreview['run_id'] ?? 0) }}</p>
                        <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                            <div class="rounded border border-gray-200 px-2 py-2 dark:border-gray-700">
                                <div class="font-medium">HIỆN TẠI</div>
                                <div>{{ number_format((int) ($identityMigration['existing_topics'] ?? $previewCounts['existing_topics'] ?? $topicCount)) }} Topic</div>
                                @if ($isFullResetRun)
                                    <div>{{ number_format($membershipRebuild) }} membership</div>
                                @endif
                            </div>
                            <div class="rounded border border-gray-200 px-2 py-2 dark:border-gray-700">
                                <div class="font-medium">SAU KHI APPLY</div>
                                <div>{{ number_format((int) ($previewCounts['effective_topics_after'] ?? $previewCounts['topics_created'] ?? 0)) }} Topic</div>
                            </div>
                        </div>
                        <div class="mt-3 text-xs space-y-1">
                            <div class="font-medium">Topic</div>
                            <div>+ Tạo mới: <strong>{{ (int) ($previewCounts['topics_created'] ?? 0) }}</strong></div>
                            <div>- Xóa cũ: <strong>{{ (int) ($previewCounts['topics_dissolved'] ?? 0) }}</strong></div>
                            <div>= Tái sử dụng: <strong>{{ (int) ($previewCounts['topics_reused'] ?? 0) }}</strong></div>
                        </div>
                        <div class="mt-3 text-xs space-y-1">
                            <div class="font-medium">Keywords</div>
                            @if ($isFullResetRun)
                                <div>{{ number_format($membershipRebuild) }} membership sẽ được dựng lại</div>
                                <div>{{ number_format((int) ($previewCounts['semantic_unassigned'] ?? $previewCounts['keywords_unassigned'] ?? 0)) }} keyword chưa được semantic gán</div>
                            @else
                                <div>Gán mới: {{ (int) ($previewCounts['keywords_assigned'] ?? 0) }} · Chuyển: {{ (int) ($previewCounts['keywords_moved'] ?? 0) }} · Bỏ gán: {{ (int) ($previewCounts['keywords_unassigned'] ?? 0) }}</div>
                            @endif
                            <div>Low confidence: {{ (int) ($previewCounts['low_confidence_members'] ?? 0) }}</div>
                            @if ($businessHardBlock)
                                <div class="font-semibold text-rose-700">HARD BLOCK — không thể Apply</div>
                            @endif
                        </div>

                        @if ($reclusterModalStep === 'confirm_apply')
                            <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100">
                                @if ($isFullResetRun)
                                    <div class="font-semibold">Bạn sắp thay thế toàn bộ cấu trúc Topic hiện tại.</div>
                                    <p class="mt-1">
                                        {{ (int) ($previewCounts['topics_dissolved'] ?? 0) }} Topic cũ sẽ bị thay thế.
                                        {{ (int) ($previewCounts['topics_created'] ?? 0) }} Topic mới sẽ được tạo.
                                    </p>
                                    <p class="mt-1 font-medium">Keywords, Articles và Focus Article không bị xóa.</p>
                                @else
                                    <div class="font-semibold">Xác nhận Apply đề xuất Topic</div>
                                    <p class="mt-1">Memberships sẽ thay đổi theo plan. Manual/lock được bảo vệ.</p>
                                @endif
                                <div class="mt-2 flex gap-2">
                                    <x-filament::button type="button" size="xs" color="gray" wire:click="cancelConfirmApplyProposal">Hủy</x-filament::button>
                                    <x-filament::button type="button" size="xs" color="danger" wire:click="applyProposal" wire:loading.attr="disabled">
                                        Xác nhận Apply
                                    </x-filament::button>
                                </div>
                            </div>
                        @else
                            <div class="topic-ai-audit-modal__actions mt-4">
                                <x-filament::button type="button" size="sm" color="gray" wire:click="backToProposalReady">Quay lại</x-filament::button>
                                <x-filament::button type="button" size="sm" color="danger" wire:click="beginConfirmApplyProposal"
                                    :disabled="! $this->canApplyProposal()">
                                    Apply
                                </x-filament::button>
                            </div>
                        @endif
                    @elseif ($reclusterModalStep === 'applying')
                        <p class="mt-3 text-sm font-medium">Đang Apply…</p>
                    @elseif ($reclusterModalStep === 'applied')
                        <p class="mt-3 text-sm font-semibold text-emerald-700">Đã Apply đề xuất Topic.</p>
                        <div class="topic-ai-audit-modal__actions mt-4">
                            <x-filament::button type="button" size="sm" color="primary" wire:click="closeReclusterModal">Đóng</x-filament::button>
                        </div>
                    @elseif ($reclusterModalStep === 'failed')
                        <p class="mt-3 text-sm font-semibold text-rose-700">Không thể tạo bản xem trước / thao tác thất bại.</p>
                        @if (! empty($this->reclusterModalError))
                            <p class="mt-1 text-xs">{{ $this->reclusterModalError }}</p>
                        @endif
                        <div class="topic-ai-audit-modal__actions mt-4">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="closeReclusterModal">Đóng</x-filament::button>
                            <x-filament::button type="button" size="sm" color="warning" wire:click="retryReclusterConfigure">Thử lại</x-filament::button>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($clusters->total() === 0)
            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                {{ __('seo-content-ai::filament.keyword.topic_empty_clusters') }}
            </p>
        @else
            <div class="cluster-index-list">
                @foreach ($clusters as $row)
                    @php
                        $topicId = (int) ($row['topic_id'] ?? 0);
                        $rowLabel = KeywordPhrasePresentation::present((string) ($row['label'] ?? ''));
                        $isTopicLocked = (bool) ($row['is_locked'] ?? false);
                        $hasMembershipLocks = (bool) ($row['has_membership_locks'] ?? false);
                        $isMcpExcluded = (bool) ($row['mcp_excluded'] ?? false);
                        $rowCanEdit = $canEditCanonical && ! $isTopicLocked;
                        $topicAiFindings = $this->aiFindingsForTopic($topicId);
                        $topicAiSeverity = $this->aiSeverityForTopic($topicId);
                        $topicAiPrimary = $topicAiFindings[0] ?? null;
                        $topicAiExtra = max(0, count($topicAiFindings) - 1);
                        $topicAiObservation = is_array($topicAiPrimary)
                            ? trim((string) ($topicAiPrimary['observation'] ?? ''))
                            : '';
                        if ($topicAiObservation === '' && is_array($topicAiPrimary)) {
                            $topicAiObservation = trim((string) ($topicAiPrimary['title'] ?? ''));
                        }
                        $topicAiTitle = is_array($topicAiPrimary)
                            ? trim((string) ($topicAiPrimary['title'] ?? ''))
                            : '';
                        $rowAiClass = $topicAiSeverity !== null
                            ? ' cluster-index-row--ai-'.$topicAiSeverity
                            : '';
                        $rowAiTitle = $topicAiSeverity !== null
                            ? __('seo-content-ai::filament.keyword.topic_ai_severity_title', [
                                'level' => ucfirst($topicAiSeverity),
                            ])
                            : null;
                    @endphp
                    <div
                        class="cluster-index-row{{ $rowAiClass }}"
                        data-topic-id="{{ $topicId }}"
                        @if ($rowAiTitle) title="{{ $rowAiTitle }}" @endif
                        wire:key="cluster-row-{{ $topicId }}-{{ $this->clusterDataEpoch }}"
                        @if ($rowCanEdit)
                            x-data="{
                                editing: false,
                                recalculating: false,
                                value: @js($rowLabel),
                                original: @js($rowLabel),
                                keywordCount: {{ (int) ($row['keyword_count'] ?? 0) }},
                                articleCount: {{ (int) ($row['article_count'] ?? 0) }},
                                internalLinkCount: {{ (int) ($row['internal_link_count'] ?? 0) }},
                                intent: @js((string) ($row['intent'] ?? '')),
                                coverage: @js((string) ($row['coverage'] ?? 'unknown')),
                                canonicalSource: @js((string) ($row['canonical_source'] ?? 'auto')),
                                state: @js((string) ($row['state'] ?? 'active')),
                                userTags: @js($row['user_tags'] ?? []),
                                tagPickerOpen: false,
                                tagDraft: '',
                                tagResults: [],
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
                                        const result = await $wire.saveTopicNameFromIndex(@js($topicId), next);
                                        if (seq !== this.renameSeq) {
                                            return;
                                        }
                                        if (result && result.ok) {
                                            this.value = result.label || next;
                                            this.original = this.value;
                                            if (result.source) {
                                                this.canonicalSource = result.source;
                                            }
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
                                },
                                async openTagPicker() {
                                    this.tagPickerOpen = !this.tagPickerOpen;
                                    if (this.tagPickerOpen) {
                                        await this.refreshTagResults();
                                    }
                                },
                                async refreshTagResults() {
                                    try {
                                        const rows = await $wire.searchTopicTags(this.tagDraft || '');
                                        const used = new Set((this.userTags || []).map((t) => Number(t.id)));
                                        this.tagResults = (rows || []).filter((t) => !used.has(Number(t.id)));
                                    } catch (e) {
                                        this.tagResults = [];
                                    }
                                },
                                exactMatchExists() {
                                    const needle = (this.tagDraft || '').trim().toLowerCase();
                                    if (needle === '') return true;
                                    return (this.tagResults || []).some((t) => String(t.name || '').toLowerCase() === needle)
                                        || (this.userTags || []).some((t) => String(t.name || '').toLowerCase() === needle);
                                },
                                async attachTagById(tagId) {
                                    const id = Number(tagId || 0);
                                    if (!id || this.recalculating) return;
                                    this.recalculating = true;
                                    try {
                                        const result = await $wire.attachTopicTag(@js($topicId), id);
                                        if (result && result.ok && Array.isArray(result.tags)) {
                                            this.userTags = result.tags;
                                        }
                                    } finally {
                                        this.recalculating = false;
                                        this.tagPickerOpen = false;
                                        this.tagDraft = '';
                                        this.tagResults = [];
                                    }
                                },
                                async attachTagByName() {
                                    const name = (this.tagDraft || '').trim();
                                    if (name === '' || this.recalculating) return;
                                    this.recalculating = true;
                                    try {
                                        const result = await $wire.attachTopicTagByName(@js($topicId), name);
                                        if (result && result.ok && Array.isArray(result.tags)) {
                                            this.userTags = result.tags;
                                        }
                                    } finally {
                                        this.recalculating = false;
                                        this.tagPickerOpen = false;
                                        this.tagDraft = '';
                                        this.tagResults = [];
                                    }
                                },
                                async removeTag(tagId) {
                                    const id = Number(tagId || 0);
                                    if (!id || this.recalculating) return;
                                    this.recalculating = true;
                                    try {
                                        const result = await $wire.detachTopicTag(@js($topicId), id);
                                        if (result && result.ok && Array.isArray(result.tags)) {
                                            this.userTags = result.tags;
                                        }
                                    } finally {
                                        this.recalculating = false;
                                    }
                                },
                            }"
                            :class="{ 'is-recalculating': recalculating }"
                        @endif
                    >
                        <div class="cluster-index-row__main">
                            <div class="cluster-index-row__title-wrap">
                                @if ($rowCanEdit)
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

                                @if ($rowCanEdit)
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
                                        @if ($isMcpExcluded)
                                            <span class="cluster-tag cluster-tag--planned">
                                                {{ __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_skipped') }}
                                            </span>
                                        @endif
                                        <template x-for="tag in userTags" :key="'ut-' + tag.id">
                                            <span class="cluster-tag cluster-tag--manual">
                                                <span x-text="tag.name"></span>
                                                <button
                                                    type="button"
                                                    class="ml-1 opacity-70 hover:opacity-100"
                                                    @click.stop="removeTag(tag.id)"
                                                    :disabled="recalculating"
                                                    title="{{ __('seo-content-ai::filament.keyword.topic_tag_remove') }}"
                                                >×</button>
                                            </span>
                                        </template>
                                        <button
                                            type="button"
                                            class="cluster-tag cluster-tag--planned"
                                            @click.stop="openTagPicker()"
                                            :disabled="recalculating"
                                        >+ {{ __('seo-content-ai::filament.keyword.topic_tag_add') }}</button>
                                    </div>
                                    <div
                                        x-show="tagPickerOpen"
                                        x-cloak
                                        class="relative mt-2 w-72 rounded-lg border border-gray-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-gray-900"
                                        @click.stop
                                        @click.outside="tagPickerOpen = false"
                                    >
                                        <input
                                            type="search"
                                            class="topic-index-cluster-edit w-full"
                                            x-model="tagDraft"
                                            placeholder="{{ __('seo-content-ai::filament.keyword.topic_tags_search_placeholder') }}"
                                            @input.debounce.200ms="refreshTagResults()"
                                            @keydown.enter.prevent.stop="attachTagByName()"
                                        />
                                        <div class="mt-2 max-h-40 space-y-1 overflow-y-auto">
                                            <template x-for="opt in tagResults" :key="'opt-' + opt.id">
                                                <button
                                                    type="button"
                                                    class="flex w-full rounded px-2 py-1 text-left text-xs hover:bg-gray-50 dark:hover:bg-white/5"
                                                    @click.stop="attachTagById(opt.id)"
                                                    x-text="opt.name"
                                                ></button>
                                            </template>
                                            <button
                                                type="button"
                                                class="flex w-full rounded px-2 py-1 text-left text-xs font-medium text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-950"
                                                x-show="(tagDraft || '').trim() !== '' && !exactMatchExists()"
                                                @click.stop="attachTagByName()"
                                            >
                                                <span>+ {{ __('seo-content-ai::filament.keyword.topic_tag_create_prefix') }}</span>
                                                <span class="ml-1" x-text="'&quot;' + (tagDraft || '').trim() + '&quot;'"></span>
                                            </button>
                                            <div
                                                class="px-2 py-1 text-xs text-gray-400"
                                                x-show="(tagDraft || '').trim() === '' && !(tagResults || []).length"
                                            >
                                                {{ __('seo-content-ai::filament.keyword.topic_tags_empty') }}
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    @include('seo-content-ai::filament.resources.keywords.pages.partials.cluster-intent-coverage-tags', [
                                        'intent' => $row['intent'] ?? '',
                                        'coverage' => $row['coverage'] ?? 'unknown',
                                        'canonicalSource' => $row['canonical_source'] ?? 'auto',
                                        'state' => $row['state'] ?? 'active',
                                        'keywordCount' => $row['keyword_count'] ?? 0,
                                    ])
                                    @if ($isMcpExcluded)
                                        <span class="cluster-tag cluster-tag--planned">
                                            {{ __('seo-content-ai::filament.keyword.keyword_item_tag_mcp_skipped') }}
                                        </span>
                                    @endif
                                    @if (! empty($row['user_tags']))
                                        <div class="cluster-tag-row">
                                            @foreach ($row['user_tags'] as $userTag)
                                                <span class="cluster-tag cluster-tag--manual">{{ $userTag['name'] ?? '' }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif

                                @if ($isTopicLocked)
                                    <span class="keyword-item-tag keyword-item-tag--planning">
                                        {{ __('seo-content-ai::filament.keyword.topic_tag_topic_locked') }}
                                    </span>
                                @elseif ($hasMembershipLocks)
                                    <span class="keyword-item-tag keyword-item-tag--planning">
                                        {{ __('seo-content-ai::filament.keyword.topic_tag_membership_locked', [
                                            'count' => (int) ($row['locked_member_count'] ?? 0),
                                        ]) }}
                                    </span>
                                @endif
                            </div>

                            <div class="cluster-index-row__meta">
                                @if ($rowCanEdit)
                                    <span x-text="`${keywordCount} {{ __('seo-content-ai::filament.keyword.topic_row_keywords_short') }} · ${articleCount} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }} · ${internalLinkCount} {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}`"></span>
                                    <span x-show="recalculating" class="topic-index-recalc"> · {{ __('seo-content-ai::filament.keyword.topic_canonical_recalculating') }}</span>
                                @else
                                    <span>
                                        {{ number_format((int) ($row['keyword_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_row_keywords_short') }}
                                        · {{ number_format((int) ($row['article_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                                        · {{ number_format((int) ($row['internal_link_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}
                                    </span>
                                @endif
                            </div>

                            @if ($topicAiFindings !== [] && $topicAiObservation !== '')
                                <div
                                    class="cluster-index-row__ai-note"
                                    x-data="{ open: false }"
                                >
                                    @if ($rowAiTitle)
                                        <span class="sr-only">{{ $rowAiTitle }}</span>
                                    @endif
                                    <div class="cluster-index-row__ai-note-label">
                                        {{ __('seo-content-ai::filament.keyword.topic_ai_note_label') }}
                                    </div>
                                    @if ($topicAiTitle !== '' && $topicAiTitle !== $topicAiObservation)
                                        <div class="cluster-index-row__ai-note-title">{{ $topicAiTitle }}</div>
                                    @endif
                                    <div class="cluster-index-row__ai-note-text">{{ $topicAiObservation }}</div>
                                    @if ($topicAiExtra > 0)
                                        <button
                                            type="button"
                                            class="cluster-index-row__ai-note-more"
                                            x-show="!open"
                                            @click.stop="open = true"
                                        >
                                            {{ __('seo-content-ai::filament.keyword.topic_ai_note_more', ['count' => $topicAiExtra]) }}
                                        </button>
                                        <ul class="cluster-index-row__ai-note-extra" x-show="open" x-cloak>
                                            @foreach (array_slice($topicAiFindings, 1) as $extraFinding)
                                                @php
                                                    $extraSev = strtoupper((string) ($extraFinding['severity'] ?? ''));
                                                    $extraTitle = trim((string) ($extraFinding['title'] ?? ''));
                                                    $extraObs = trim((string) ($extraFinding['observation'] ?? ''));
                                                @endphp
                                                <li>
                                                    @if ($extraSev !== '')
                                                        <span class="cluster-index-row__ai-note-sev">[{{ $extraSev }}]</span>
                                                    @endif
                                                    @if ($extraTitle !== '')
                                                        <strong>{{ $extraTitle }}</strong>
                                                    @endif
                                                    @if ($extraObs !== '')
                                                        <span>{{ $extraObs }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div
                            class="cluster-index-row__share"
                            title="{{ __('seo-content-ai::filament.keyword.topic_topical_share_tooltip') }}"
                        >
                            <span>{{ \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTopicalShareCalculator::formatPercent((float) ($row['topical_share'] ?? 0)) }}</span>
                        </div>

                        <div class="cluster-index-row__actions">
                            <a
                                href="{{ $this->topicUrl($topicId) }}"
                                class="topic-index-detail-btn"
                                title="{{ __('seo-content-ai::filament.keyword.topic_view_cluster') }}"
                                aria-label="{{ __('seo-content-ai::filament.keyword.topic_view_cluster') }}"
                            >
                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
                            </a>
                            @if ($canEditCanonical)
                                @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-row-actions-menu', [
                                    'topicId' => $topicId,
                                    'topicName' => (string) ($row['label'] ?? $row['name'] ?? ''),
                                    'canDissolve' => $canDissolve && ! $isTopicLocked,
                                    'canMutateMcp' => $canEditCanonical && ! $topicMutationsLocked,
                                    'mcpExcluded' => (bool) ($row['mcp_excluded'] ?? false),
                                ])
                            @elseif ($canDissolve && ! $isTopicLocked)
                                @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-dissolve-row-action', [
                                    'topicId' => $topicId,
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
    </div>
</x-filament-panels::page>
