@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

    $detail = $this->getDetail();
    $keywords = $this->getKeywords();
    $dnaMap = $this->getKeywordDnaMap();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $reclusterRunning = (bool) ($this->reclusterRunning ?? false);
    $topicMutationsLocked = $reclusterRunning || $this->isTopicMutationLocked();
    $canEditCanonical = $this->canEditTopicName();
    $canDissolve = $this->canDissolveCluster();
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

        <a href="{{ $this->backUrl() }}" class="topic-index-link text-sm">← {{ __('seo-content-ai::filament.keyword.topic_cluster_title') }}</a>

        @if ($reclusterStatus === 'queued' || $reclusterStatus === 'running' || $reclusterRunning || $topicMutationsLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_title') }}</div>
                <p class="mt-1 opacity-90">{{ __('seo-content-ai::filament.keyword.topic_recluster_lock_banner_body') }}</p>
            </div>
        @endif

        @if ($detail)
            @php
                $displayLabel = KeywordPhrasePresentation::present((string) ($detail['label'] ?? ''));
                $isTopicLocked = (bool) ($detail['is_locked'] ?? false);
                $hasMembershipLocks = (bool) ($detail['has_membership_locks'] ?? false);
            @endphp

            <header class="cluster-detail-header space-y-2" wire:key="cluster-detail-header-{{ $this->clusterDataEpoch }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
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
                                    async save() {
                                        const next = (this.value || '').trim().replace(/\s+/g, ' ');
                                        if (next === '' || next === this.original) {
                                            this.cancel();
                                            return;
                                        }
                                        this.saving = true;
                                        try {
                                            const label = await $wire.saveTopicName(next);
                                            this.original = label || next;
                                            this.value = this.original;
                                            this.editing = false;
                                        } catch (e) {
                                            this.value = this.original;
                                        } finally {
                                            this.saving = false;
                                        }
                                    }
                                }"
                            >
                                <template x-if="!editing">
                                    <button type="button" class="text-left text-xl font-semibold" @click="startEdit()">
                                        <span x-text="value"></span>
                                    </button>
                                </template>
                                <template x-if="editing">
                                    <input
                                        x-ref="input"
                                        type="text"
                                        class="fi-input w-full max-w-xl rounded-lg border-gray-300 text-lg dark:border-gray-600 dark:bg-gray-900"
                                        x-model="value"
                                        @keydown.enter.prevent="save()"
                                        @keydown.escape.prevent="cancel()"
                                        @blur="save()"
                                    >
                                </template>
                            </div>
                        @else
                            <h2 class="text-xl font-semibold">{{ $displayLabel }}</h2>
                        @endif

                        <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                            <span>#{{ (int) $detail['topic_id'] }}</span>
                            <span>{{ __('seo-content-ai::filament.keyword.topic_stat_keywords', [
                                'count' => number_format((int) ($detail['keyword_count'] ?? 0)),
                            ]) }}</span>
                            <span>{{ __('seo-content-ai::filament.keyword.topic_stat_articles', [
                                'count' => number_format((int) ($detail['article_count'] ?? 0)),
                            ]) }}</span>
                            @if ($isTopicLocked)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                    {{ __('seo-content-ai::filament.keyword.topic_tag_topic_locked') }}
                                </span>
                            @elseif ($hasMembershipLocks)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    {{ __('seo-content-ai::filament.keyword.topic_tag_membership_locked', [
                                        'count' => (int) ($detail['locked_member_count'] ?? 0),
                                    ]) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($canDissolve)
                        <button
                            type="button"
                            class="fi-btn fi-btn-size-sm fi-color-danger"
                            wire:click="dissolveCurrentTopic"
                            wire:confirm="{{ __('seo-content-ai::filament.keyword.topic_dissolve_heading_named', ['label' => $displayLabel]) }}"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                        </button>
                    @endif
                </div>
            </header>

            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900">
                        <tr>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.keyword.topic_member_phrase') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.keyword.topic_member_flags') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.keyword.topic_member_dna') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($keywords as $member)
                            @php
                                $kid = (int) ($member['keyword_id'] ?? 0);
                                $dna = $dnaMap[$kid] ?? [];
                            @endphp
                            <tr wire:key="topic-member-{{ $kid }}">
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ KeywordPhrasePresentation::present((string) ($member['phrase'] ?? '')) }}</div>
                                    <div class="text-xs text-gray-500">{{ (string) ($member['source'] ?? '') }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @if (! empty($member['is_seed']))
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                                                {{ __('seo-content-ai::filament.keyword.topic_member_seed') }}
                                            </span>
                                        @endif
                                        @if (! empty($member['is_locked']))
                                            <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                                {{ __('seo-content-ai::filament.keyword.topic_member_locked') }}
                                            </span>
                                        @endif
                                        @if (! empty($member['is_seo_keyword']))
                                            <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-900 dark:bg-sky-950 dark:text-sky-100">SEO</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                    {{ $dna !== [] ? implode(' · ', array_slice($dna, 0, 8)) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-3 py-8 text-center text-gray-500">
                                    {{ __('seo-content-ai::filament.keyword.topic_empty_cluster_keywords') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $keywords->links() }}
            </div>
        @else
            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-sm text-gray-500">
                {{ __('seo-content-ai::filament.keyword.topic_empty_clusters') }}
            </p>
        @endif
    </div>
</x-filament-panels::page>
