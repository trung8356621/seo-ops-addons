@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $detail = $this->getDetail();
    $keywords = $this->getKeywords();
    $dnaMap = $this->getKeywordDnaMap();
    $siteId = $this->resolveKeywordWorkspaceSiteId();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $topicMutationsLocked = $reclusterRunning || $this->isTopicMutationLocked();
    $canEditPermission = $this->hasTopicClusterMutationPermission();
    $canEditCanonical = $this->canEditClusterCanonical();
    $reclusterStatus = is_array($this->reclusterResult ?? null)
        ? (string) ($this->reclusterResult['status'] ?? '')
        : '';
    $reclusterPollAttr = $reclusterRunning ? 'wire:poll.5s="pollReclusterResult"' : '';
    $keywordDetailPanelConfig = [
        'livewireId' => $this->getId(),
        'errorLabel' => __('seo-content-ai::filament.keyword.drawer_load_error'),
        'selectedKeywordId' => $this->selectedKeywordId,
    ];
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

        <a href="{{ $this->backUrl() }}" class="topic-index-link text-sm">← {{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}</a>

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterRunning || $topicMutationsLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_title') }}</div>
                <p class="mt-1 opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_body') }}</p>
            </div>
        @elseif ($reclusterStatus === 'failed')
            <p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100">
                {{ __('seo-content-ai::filament.keyword.topic_recluster_failed_title') }}
                @if (! empty($this->reclusterResult['error']))
                    — {{ $this->reclusterResult['error'] }}
                @endif
            </p>
        @endif

        <div class="keyword-detail-layout keyword-master-detail-page">
            <x-seo-content-ai::list-table-loading-shell
                class="keyword-table-shell min-w-0 space-y-5"
                preset="livewire-page"
                targets="keywordLanguageFilter,updatedKeywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged"
            >

        @if ($detail)
            @php
                $intentCounts = is_array($detail['intent_counts'] ?? null) ? $detail['intent_counts'] : [];
                $intentTotal = array_sum(array_map('intval', $intentCounts));
                $intentOrder = ['commercial', 'informational', 'transactional', 'navigational'];
                $intentLine = [];
                foreach ($intentOrder as $ikey) {
                    $c = (int) ($intentCounts[$ikey] ?? 0);
                    if ($c > 0 && $intentTotal > 0) {
                        $intentLine[] = ucfirst($ikey).' '.round(($c / $intentTotal) * 100).'%';
                    }
                }
                $displayLabel = KeywordPhrasePresentation::present((string) ($detail['label'] ?? ''));
                $state = ((int) ($detail['keyword_count'] ?? 0)) === 0 ? 'planned' : 'active';
            @endphp

            <header class="cluster-detail-header" wire:key="cluster-detail-header-{{ $this->clusterDataEpoch }}">
                <div class="min-w-0 flex-1 space-y-2">
                    @if ($canEditCanonical)
                        <div
                            wire:ignore.self
                            x-data="{
                                editing: false,
                                value: @js($displayLabel),
                                original: @js($displayLabel),
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
                                syncLabel(next) {
                                    const label = (next || '').trim();
                                    if (label === '') return;
                                    this.original = label;
                                    this.value = label;
                                },
                                async save() {
                                    const next = (this.value || '').trim().replace(/\s+/g, ' ');
                                    if (next === '' || next === this.original) {
                                        this.cancel();
                                        return;
                                    }
                                    this.saving = true;
                                    try {
                                        const saved = await $wire.saveClusterCanonicalPhrase(next);
                                        this.syncLabel(typeof saved === 'string' ? saved : next);
                                        this.editing = false;
                                    } catch (e) {
                                        this.value = this.original;
                                        this.editing = false;
                                    } finally {
                                        this.saving = false;
                                    }
                                }
                            }"
                            x-on:cluster-canonical-sync.window="syncLabel($event.detail?.label)"
                        >
                            <h1
                                x-show="!editing"
                                @dblclick="startEdit()"
                                class="cluster-detail-header__title"
                                title="{{ __('seo-content-ai::filament.keyword.topic_canonical_edit_hint') }}"
                                x-text="value"
                            ></h1>
                            <input
                                x-show="editing"
                                x-cloak
                                x-ref="input"
                                type="text"
                                class="topic-index-cluster-edit cluster-detail-header__title-input"
                                x-model="value"
                                @keydown.enter.prevent.stop="save()"
                                @keydown.escape.prevent.stop="cancel()"
                                @blur="if (editing && !saving) save()"
                                :disabled="saving"
                            />
                        </div>
                    @else
                        <h1 class="cluster-detail-header__title">{{ $displayLabel }}</h1>
                    @endif

                    @include('seo-content-ai::filament.resources.keywords.pages.partials.cluster-intent-coverage-tags', [
                        'intent' => $detail['intent'] ?? '',
                        'coverage' => $detail['coverage'] ?? 'unknown',
                        'canonicalSource' => $detail['canonical_source'] ?? 'auto',
                        'state' => $state,
                        'keywordCount' => $detail['keyword_count'] ?? 0,
                    ])

                    <p class="cluster-detail-header__stats">
                        {{ number_format((int) $detail['keyword_count']) }} {{ __('seo-content-ai::filament.keyword.topic_row_keywords_short') }}
                        · {{ number_format((int) $detail['article_count']) }} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                        · {{ number_format((int) $detail['internal_links']) }} {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}
                    </p>

                    @if ($detail['last_analyzed'])
                        <p class="cluster-detail-header__updated">
                            {{ __('seo-content-ai::filament.keyword.topic_last_analyzed') }}:
                            {{ \Illuminate\Support\Carbon::parse($detail['last_analyzed'])->format('d/m/Y H:i') }}
                        </p>
                    @endif

                    @if ($intentLine !== [])
                        <p class="cluster-detail-header__intent-line">{{ implode(' · ', $intentLine) }}</p>
                    @endif
                </div>

                <div class="cluster-detail-header__actions flex flex-wrap items-center gap-2">
                    @if ($canEditPermission)
                        <x-filament::button
                            type="button"
                            color="gray"
                            outlined
                            wire:click="fixTopicKeywords"
                            wire:loading.attr="disabled"
                            wire:target="fixTopicKeywords"
                            :disabled="$topicMutationsLocked"
                            :title="$topicMutationsLocked ? __('seo-content-ai::filament.keyword.topic_recluster_mutations_locked') : null"
                        >
                            <span wire:loading.remove wire:target="fixTopicKeywords" class="inline-flex items-center gap-1.5">
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.topic_fix_keywords_action') }}
                            </span>
                            <span wire:loading wire:target="fixTopicKeywords" class="inline-flex items-center gap-1.5">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.topic_fix_keywords_working') }}
                            </span>
                        </x-filament::button>
                    @endif
                    @if ($this->canDissolveCluster() || ($canEditPermission && $topicMutationsLocked))
                        <x-filament::button
                            type="button"
                            color="danger"
                            outlined
                            wire:click="dissolveTopicCluster({{ \Illuminate\Support\Js::from((string) ($detail['cluster_key'] ?? '')) }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="dissolveTopicCluster"
                            :disabled="$topicMutationsLocked || ! $this->canDissolveCluster()"
                            :title="$topicMutationsLocked ? __('seo-content-ai::filament.keyword.topic_recluster_mutations_locked') : null"
                        >
                            <span wire:loading.remove wire:target="dissolveTopicCluster">
                                {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                            </span>
                            <span wire:loading wire:target="dissolveTopicCluster" class="inline-flex items-center gap-1.5">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                {{ __('seo-content-ai::filament.keyword.topic_dissolve_working') }}
                            </span>
                        </x-filament::button>
                    @endif
                </div>
            </header>

            @if (! empty($detail['idea_coverage']['dna_branches']))
                <details class="cluster-detail-dna-panel">
                    <summary>{{ __('seo-content-ai::filament.keyword.topic_idea_coverage_title') }}</summary>
                    @php $idea = $detail['idea_coverage']; @endphp
                    <div class="cluster-detail-dna-panel__body">
                        @foreach ($idea['dna_branches'] as $branch)
                            <div class="cluster-detail-dna-panel__row">
                                <span>{{ KeywordPhrasePresentation::present((string) ($branch['value'] ?? '')) }}</span>
                                <span class="text-xs text-gray-500">
                                    {{ number_format((int) ($branch['keyword_count'] ?? 0)) }} KW
                                    · {{ number_format((int) ($branch['article_count'] ?? 0)) }} {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            <div class="keyword-item-list space-y-2" wire:key="cluster-keyword-list-{{ $this->clusterDataEpoch }}">
                @forelse ($keywords as $keyword)
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-item', [
                        'keyword' => $keyword,
                        'context' => 'cluster',
                        'siteId' => $siteId,
                        'dnaValues' => $dnaMap[(int) $keyword->id] ?? [],
                        'clusterKey' => (string) ($detail['cluster_key'] ?? ''),
                        'showCheckbox' => false,
                    ])
                @empty
                    <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                        {{ __('seo-content-ai::filament.keyword.topic_empty_cluster_keywords') }}
                    </p>
                @endforelse
            </div>
            <div>{{ $keywords->links() }}</div>
        @else
            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                {{ __('seo-content-ai::filament.keyword.topic_empty_clusters') }}
            </p>
        @endif

            </x-seo-content-ai::list-table-loading-shell>

            @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-detail-drawer', [
                'keywordDetailPanelConfig' => $keywordDetailPanelConfig,
            ])
        </div>

        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-move-cluster-modal')
    </div>
</x-filament-panels::page>
