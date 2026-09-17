@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopics();
    $topicMutationsLocked = $reclusterRunning || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicClusterMutationPermission();
    $canDissolve = $this->canDissolveCluster();
    $canEditCanonical = $this->canEditClusterCanonical();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterPollAttr = $reclusterRunning ? 'wire:poll.5s="pollReclusterResult"' : '';

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
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_assigned', [
                    'count' => number_format($assignedCount),
                ]) }}</span>
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
            targets="clusterSearch,lockFilter,clusterSort,hasArticles,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged,applyClusterSearch,clearClusterSearch,updatedLockFilter,updatedHasArticles,updatedClusterSort"
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
                            <x-filament::button type="button" size="sm" color="warning" wire:click="beginConfirmRecluster" :disabled="$reclusterRunning">
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
                            <x-filament::button type="button" size="sm" color="gray" wire:click="beginConfirmRecluster" :disabled="$reclusterRunning">
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
            </div>
            <div class="topic-index-filters topic-index-toolbar__filters">
                <x-select size="sm" wire:model.live="lockFilter">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_all') }}</option>
                    <option value="topic_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_topic') }}</option>
                    <option value="membership_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_membership') }}</option>
                    <option value="unlocked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_unlocked') }}</option>
                </x-select>
                <x-select size="sm" wire:model.live="clusterSort">
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
                {{ number_format((int) ($m['topics_before'] ?? 0)) }}→{{ number_format((int) ($m['topics_after'] ?? 0)) }}
                · {{ number_format((int) ($m['memberships_written'] ?? 0)) }} memberships
                · {{ number_format((int) ($m['topics_reused'] ?? 0)) }} reused
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

                        <div class="cluster-index-row__share">
                            <span>—</span>
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
