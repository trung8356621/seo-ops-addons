@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $summary = $this->getSummary();
    $clusters = $this->getClusters();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $confirmRecluster = (bool) ($this->confirmRecluster ?? false);
    $canRecluster = $this->canReclusterTopics();
    $topicMutationsLocked = $reclusterRunning || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicMutationPermission();
    $canDissolve = $this->canDissolveTopic();
    $canEditCanonical = $this->canEditTopicName();
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
            >
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_seo', [
                    'count' => number_format((int) ($summary['seo_eligible_keywords'] ?? 0)),
                ]) }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_assigned', [
                    'count' => number_format((int) ($summary['assigned'] ?? 0)),
                ]) }}</span>
                <span aria-hidden="true">·</span>
                <a href="{{ $this->unassignedUrl() }}" class="topic-index-compact-stats__link">
                    {{ __('seo-content-ai::filament.keyword.topic_compact_stats_unassigned', [
                        'count' => number_format((int) ($summary['unassigned'] ?? 0)),
                    ]) }}
                </a>
                <span aria-hidden="true">·</span>
                <span>{{ __('seo-content-ai::filament.keyword.topic_compact_stats_topics', [
                    'count' => number_format((int) ($summary['topic_count'] ?? 0)),
                ]) }}</span>
            </p>
        </header>

        <x-seo-content-ai::list-table-loading-shell
            class="space-y-4"
            preset="livewire-page"
            targets="clusterSearch,lockFilter,clusterSort,hasArticles,keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged,applyClusterSearch,clearClusterSearch,updatedLockFilter,updatedHasArticles,updatedClusterSort"
        >
        <div class="topic-index-toolbar flex flex-wrap items-center gap-3">
            <div class="flex min-w-[14rem] flex-1 items-center gap-2">
                <input
                    type="search"
                    wire:model="clusterSearchInput"
                    wire:keydown.enter.prevent="applyClusterSearch"
                    class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900"
                    placeholder="{{ __('seo-content-ai::filament.keyword.topic_search_placeholder') }}"
                >
                <button type="button" wire:click="applyClusterSearch" class="topic-index-link text-sm">
                    {{ __('seo-content-ai::filament.keyword.topic_search_apply') }}
                </button>
                @if ($clusterSearch !== '')
                    <button type="button" wire:click="clearClusterSearch" class="topic-index-link text-sm">
                        {{ __('seo-content-ai::filament.keyword.topic_search_clear') }}
                    </button>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-select wire:model.live="clusterSort" wrapClass="x-select-wrap x-select-wrap--narrow">
                    <option value="name_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_name_asc') }}</option>
                    <option value="name_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_name_desc') }}</option>
                    <option value="keywords_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_keywords_desc') }}</option>
                    <option value="keywords_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_keywords_asc') }}</option>
                    <option value="articles_desc">{{ __('seo-content-ai::filament.keyword.topic_sort_articles_desc') }}</option>
                    <option value="articles_asc">{{ __('seo-content-ai::filament.keyword.topic_sort_articles_asc') }}</option>
                </x-select>
                <x-select wire:model.live="lockFilter" wrapClass="x-select-wrap x-select-wrap--narrow">
                    <option value="">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_all') }}</option>
                    <option value="topic_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_topic') }}</option>
                    <option value="membership_locked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_membership') }}</option>
                    <option value="unlocked">{{ __('seo-content-ai::filament.keyword.topic_lock_filter_unlocked') }}</option>
                </x-select>
                <label class="topic-index-check">
                    <input type="checkbox" wire:model.live="hasArticles">
                    {{ __('seo-content-ai::filament.keyword.topic_has_articles') }}
                </label>
            </div>

            <div class="ml-auto flex items-center gap-2">
                @if ($canRecluster)
                    @if ($confirmRecluster)
                        <button type="button" wire:click="runTopicRecluster" class="fi-btn fi-btn-size-sm fi-color-danger">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_confirm') }}
                        </button>
                        <button type="button" wire:click="cancelConfirmRecluster" class="fi-btn fi-btn-size-sm">
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_cancel') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="beginConfirmRecluster"
                            @disabled($topicMutationsLocked)
                            class="fi-btn fi-btn-size-sm fi-color-primary"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_recluster_action') }}
                        </button>
                    @endif
                @endif
            </div>
        </div>

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterRunning || $topicMutationsLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_title') }}</div>
                <p class="mt-1 opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_body') }}</p>
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
                    @endphp
                    <div
                        class="cluster-index-row"
                        data-topic-id="{{ $topicId }}"
                        wire:key="topic-row-{{ $topicId }}-{{ $this->clusterDataEpoch }}"
                        @if ($canEditCanonical && ! $isTopicLocked)
                            x-data="{
                                editing: false,
                                value: @js($rowLabel),
                                original: @js($rowLabel),
                                saving: false,
                                startEdit() {
                                    if (this.saving) return;
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
                                    this.saving = true;
                                    this.editing = false;
                                    try {
                                        const result = await $wire.saveTopicNameFromIndex(@js($topicId), next);
                                        if (result && result.ok) {
                                            this.original = result.label || next;
                                            this.value = this.original;
                                        } else {
                                            this.value = this.original;
                                        }
                                    } catch (e) {
                                        this.value = this.original;
                                    } finally {
                                        this.saving = false;
                                    }
                                }
                            }"
                        @endif
                    >
                        <div class="cluster-index-row__main min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($canEditCanonical && ! $isTopicLocked)
                                    <template x-if="!editing">
                                        <button type="button" class="topic-index-title-btn text-left font-medium" @click="startEdit()">
                                            <span x-text="value"></span>
                                        </button>
                                    </template>
                                    <template x-if="editing">
                                        <input
                                            x-ref="input"
                                            type="text"
                                            class="fi-input w-full max-w-md rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900"
                                            x-model="value"
                                            @keydown.enter.prevent="save()"
                                            @keydown.escape.prevent="cancel()"
                                            @blur="save()"
                                        >
                                    </template>
                                @else
                                    <a href="{{ $this->topicUrl($topicId) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $rowLabel }}
                                    </a>
                                @endif

                                @if ($isTopicLocked)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                        {{ __('seo-content-ai::filament.keyword.topic_tag_topic_locked') }}
                                    </span>
                                @elseif ($hasMembershipLocks)
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                        {{ __('seo-content-ai::filament.keyword.topic_tag_membership_locked', [
                                            'count' => (int) ($row['locked_member_count'] ?? 0),
                                        ]) }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                                <a href="{{ $this->topicUrl($topicId) }}" class="topic-index-link">
                                    {{ __('seo-content-ai::filament.keyword.topic_stat_keywords', [
                                        'count' => number_format((int) ($row['keyword_count'] ?? 0)),
                                    ]) }}
                                </a>
                                <span>
                                    {{ __('seo-content-ai::filament.keyword.topic_stat_articles', [
                                        'count' => number_format((int) ($row['article_count'] ?? 0)),
                                    ]) }}
                                </span>
                            </div>
                        </div>
                        <div class="cluster-index-row__actions flex items-center gap-2">
                            <a href="{{ $this->topicUrl($topicId) }}" class="topic-index-link text-sm">
                                {{ __('seo-content-ai::filament.keyword.topic_open_detail') }}
                            </a>
                            @if ($canDissolve && ! $isTopicLocked)
                                <button
                                    type="button"
                                    class="topic-index-link text-sm text-rose-600"
                                    wire:click="dissolveTopic({{ $topicId }})"
                                    wire:confirm="{{ __('seo-content-ai::filament.keyword.topic_dissolve_heading_named', ['label' => $rowLabel]) }}"
                                >
                                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                {{ $clusters->links() }}
            </div>
        @endif
        </x-seo-content-ai::list-table-loading-shell>
    </div>
</x-filament-panels::page>
