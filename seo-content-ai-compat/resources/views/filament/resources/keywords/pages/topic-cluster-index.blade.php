@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopics();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterActive = $reclusterRunning
        || $reclusterStatus === 'queued'
        || $reclusterStatus === 'running';
    $topicMutationsLocked = $reclusterActive || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicClusterMutationPermission();
    $canDissolve = $this->canDissolveCluster();
    $canEditCanonical = $this->canEditClusterCanonical();
    $reclusterPollAttr = $reclusterActive ? 'wire:poll.5s="pollReclusterResult"' : '';

    $assignedCount = (int) ($summary['assigned'] ?? $summary['clustered'] ?? 0);
    $unassignedCount = (int) ($summary['unassigned'] ?? $summary['unclustered'] ?? 0);
    $topicCount = (int) ($summary['topic_count'] ?? 0);
    $seoEligibleCount = (int) ($summary['seo_eligible_keywords'] ?? 0);
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-cluster-index-page max-w-full">
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
                                    <x-filament::button type="button" size="sm" color="gray" wire:click="cancelConfirmRecluster">
                                        {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                                    </x-filament::button>
                                    <x-filament::button type="button" size="sm" color="warning" wire:click="runTopicRecluster">
                                        {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @else
                            <div class="topic-index-stale-alert__confirm-actions" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                <x-filament::button type="button" size="sm" color="warning" wire:click="beginConfirmRecluster" :disabled="$reclusterActive || $topicMutationsLocked">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                </x-filament::button>
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
                                <x-filament::button type="button" size="sm" color="gray" wire:click="cancelConfirmRecluster">
                                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                                </x-filament::button>
                                <x-filament::button type="button" size="sm" color="warning" wire:click="runTopicRecluster">
                                    {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                                </x-filament::button>
                            </div>
                        </div>
                    @else
                        <div class="topic-index-recluster-idle__row">
                            <x-filament::button type="button" size="sm" color="gray" wire:click="beginConfirmRecluster" :disabled="$reclusterActive || $topicMutationsLocked">
                                {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                            </x-filament::button>
                            @php
                                $aiAuditCan = $this->canRunAiAuditAndTags();
                                $aiAuditLabel = $this->aiAuditButtonLabel();
                            @endphp
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
                    @endif
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

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterActive || $topicMutationsLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_title') }}</div>
                <p class="mt-1 opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_body') }}</p>
                @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterActive)
                    <p class="mt-1 font-medium opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_running') }}</p>
                @endif
            </div>
        @elseif ($reclusterStatus === 'succeeded' || $reclusterStatus === 'completed')
            @php $m = $this->reclusterResult['metrics'] ?? []; @endphp
            <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_result_title') }}:
                {{ number_format((int) ($m['topics_before'] ?? 0)) }}→{{ number_format((int) ($m['topics_after'] ?? 0)) }}
                · {{ number_format((int) ($m['memberships_written'] ?? 0)) }} memberships
                · {{ number_format((int) ($m['topics_reused'] ?? 0)) }} reused
                @if (isset($m['topics_created']))
                    · {{ number_format((int) $m['topics_created']) }} created
                @endif
                @if (isset($m['topics_dissolved']))
                    · {{ number_format((int) $m['topics_dissolved']) }} dissolved
                @endif
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
                        $topicId = (int) ($row['topic_id'] ?? 0);
                        $rowLabel = KeywordPhrasePresentation::present((string) ($row['label'] ?? ''));
                        $isTopicLocked = (bool) ($row['is_locked'] ?? false);
                        $hasMembershipLocks = (bool) ($row['has_membership_locks'] ?? false);
                        $rowCanEdit = $canEditCanonical && ! $isTopicLocked;
                    @endphp
                    <div
                        class="cluster-index-row"
                        data-topic-id="{{ $topicId }}"
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
                                    'canDissolve' => $canDissolve && ! $isTopicLocked,
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
