@php
    $languageReady = (bool) $this->keywordLanguagePinned;
    $groups = $languageReady
        ? $this->getGroupsPaginator()
        : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
    $unassignedCount = $languageReady ? $this->getUnassignedCount() : 0;
    $focusGroupId = (int) ($this->focusGroupId ?? 0);
    $canMutate = $this->canMutateKeywordGroups();
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
@endphp

<x-filament-panels::page class="keyword-workspace-page keyword-groups-page max-w-full">
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-4">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <header class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.keyword.keyword_group_page_title') }}
            </h2>
            @if ($canMutate)
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    wire:click="refreshSemanticGroups"
                    wire:loading.attr="disabled"
                    wire:target="refreshSemanticGroups"
                    @disabled($this->analysisActive || ! $languageReady)
                >
                    <svg wire:loading wire:target="refreshSemanticGroups" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span>{{ __('seo-content-ai::filament.keyword.keyword_group_refresh') }}</span>
                </button>
            @endif
        </header>

        @if ($languageReady && $analysisPhase !== 'idle')
            <div
                class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-800 dark:bg-gray-900"
                @if ($analysisActive) wire:poll.5s="refreshAnalysisStatus" @endif
            >
                <p class="font-medium text-gray-950 dark:text-white">
                    {{ __('seo-content-ai::filament.keyword.keyword_group_analysis_phase_'.$analysisPhase) }}
                </p>
                @if (in_array($analysisPhase, ['finalizing', 'finalized'], true) && ($analysisGroups > 0 || $analysisMembers > 0))
                    <p class="mt-1 text-gray-600 dark:text-gray-300">
                        {{ __('seo-content-ai::filament.keyword.keyword_group_analysis_counts', ['groups' => $analysisGroups, 'members' => $analysisMembers]) }}
                    </p>
                @endif
                @if ($analysisError)
                    <p class="mt-1 text-danger-600 dark:text-danger-400">{{ $analysisError }}</p>
                @endif
                @if ($analysisWorkerWarning)
                    <p class="mt-1 text-warning-600 dark:text-warning-400">
                        {{ __('seo-content-ai::filament.keyword.keyword_group_analysis_worker') }}
                    </p>
                @endif
            </div>
        @endif

        @if ($languageReady)
            <div class="flex flex-wrap items-center gap-2">
                <form
                    class="relative w-full max-w-sm"
                    x-data
                    @submit.prevent="$wire.applyGroupSearch(($el.querySelector('input')?.value || '').trim())"
                >
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="newGroupName"
                        class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-9 text-sm dark:border-gray-700 dark:bg-gray-900"
                        placeholder="{{ __('seo-content-ai::filament.keyword.keyword_group_name') }}"
                        @keydown.enter.prevent="$wire.applyGroupSearch(($event.target.value || '').trim())"
                        autocomplete="off"
                    />
                    <svg
                        wire:loading
                        wire:target="applyGroupSearch, newGroupName"
                        class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-primary-600"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </form>
                @if ($canMutate)
                    <button
                        type="button"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium disabled:opacity-50 dark:border-gray-700"
                        wire:click="createGroup"
                        wire:loading.attr="disabled"
                        wire:target="createGroup"
                    >
                        <span wire:loading.remove wire:target="createGroup">{{ __('seo-content-ai::filament.keyword.keyword_group_create') }}</span>
                        <span wire:loading wire:target="createGroup">…</span>
                    </button>
                @endif
            </div>
            @php $groupSearchQuery = trim((string) $this->newGroupName); @endphp
            @if ($groupSearchQuery !== '')
                <p class="text-sm text-gray-600 dark:text-gray-300" wire:key="group-search-status-{{ md5($groupSearchQuery) }}-{{ $groups->total() }}">
                    @if ($groups->total() > 0)
                        {{ __('seo-content-ai::filament.keyword.keyword_group_search_matches', [
                            'count' => number_format($groups->total()),
                            'query' => $groupSearchQuery,
                        ]) }}
                    @else
                        {{ __('seo-content-ai::filament.keyword.keyword_group_search_matches_empty', [
                            'query' => $groupSearchQuery,
                        ]) }}
                    @endif
                </p>
            @endif
        @endif

        @if ($languageReady)
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-800 dark:bg-gray-900">
            <span class="font-medium text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.keyword.keyword_group_unassigned_summary', ['count' => number_format($unassignedCount)]) }}
            </span>
        </div>

        <div wire:loading.class="opacity-60 pointer-events-none" wire:target="keywordLanguageFilter, applyGroupSearch, newGroupName">
        @forelse ($groups as $group)
            @php
                $groupId = (int) $group['id'];
                $focused = $focusGroupId === $groupId;
                $isManual = (bool) ($group['is_manual'] ?? false);
                $members = $this->loadedMembers[$groupId] ?? ($group['members'] ?? []);
                $hasMore = array_key_exists($groupId, $this->memberHasMore)
                    ? (bool) $this->memberHasMore[$groupId]
                    : (bool) ($group['members_has_more'] ?? false);
            @endphp
            <article
                id="keyword-group-{{ $groupId }}"
                wire:key="keyword-group-card-{{ $groupId }}-{{ $this->keywordLanguageFilter }}"
                class="rounded-xl border bg-white p-4 dark:bg-gray-900 {{ $focused ? 'border-primary-500' : 'border-gray-200 dark:border-gray-800' }}"
                @if ($canMutate)
                    x-data="{
                        editing: false,
                        saving: false,
                        value: @js($group['name']),
                        original: @js($group['name']),
                        async save() {
                            if (this.saving) {
                                return;
                            }
                            const next = (this.value || '').trim();
                            if (next === '' || next === this.original) {
                                this.value = this.original;
                                this.editing = false;
                                return;
                            }
                            this.saving = true;
                            try {
                                const ok = await $wire.renameGroup({{ $groupId }}, next);
                                if (ok) {
                                    this.original = next;
                                    this.editing = false;
                                }
                            } finally {
                                this.saving = false;
                            }
                        },
                        cancel() {
                            if (this.saving) {
                                return;
                            }
                            this.value = this.original;
                            this.editing = false;
                        },
                        confirmDelete() {
                            const msg = @js(__('seo-content-ai::filament.keyword.keyword_group_delete_confirm', ['name' => $group['name']]));
                            if (! window.confirm(msg)) {
                                return;
                            }
                            $wire.deleteGroup({{ $groupId }});
                        }
                    }"
                    @if ($focused) x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif
                @elseif ($focused)
                    x-data x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))"
                @endif
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        @if ($canMutate)
                            <div
                                class="text-base font-semibold text-gray-950 dark:text-white"
                                x-show="!editing && !saving"
                                @dblclick="if (!saving) { editing = true; $nextTick(() => $refs.renameInput?.focus()) }"
                                title="{{ __('seo-content-ai::filament.keyword.keyword_group_rename_hint') }}"
                                x-text="original"
                            ></div>
                            <div
                                class="keyword-group-rename-field"
                                x-show="editing || saving"
                                x-cloak
                                data-rename-loading-target="renameGroup({{ $groupId }})"
                            >
                                <input
                                    x-ref="renameInput"
                                    type="text"
                                    class="keyword-group-rename-input"
                                    :class="{ 'keyword-group-rename-input--busy': saving }"
                                    x-model="value"
                                    :disabled="saving"
                                    wire:loading.attr="disabled"
                                    wire:target="renameGroup({{ $groupId }}), recheckGroup, deleteGroup"
                                    @keydown.enter.prevent="save()"
                                    @keydown.escape.prevent="cancel()"
                                    @blur="save()"
                                />
                                <svg
                                    class="keyword-group-rename-spinner text-primary-600"
                                    x-show="saving"
                                    x-cloak
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                            </div>
                            <div
                                class="keyword-group-rename-status"
                                x-show="saving"
                                x-cloak
                                role="status"
                                aria-live="polite"
                                data-rename-status-for="renameGroup({{ $groupId }})"
                            >
                                <svg class="keyword-group-rename-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                <span>{{ __('seo-content-ai::filament.keyword.keyword_group_rename_saving') }}</span>
                            </div>
                        @else
                            <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $group['name'] }}</div>
                        @endif
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                            <span class="{{ $isManual ? 'cluster-tag cluster-tag--manual' : 'cluster-tag cluster-tag--auto' }}">
                                {{ $isManual
                                    ? __('seo-content-ai::filament.keyword.keyword_group_source_manual')
                                    : __('seo-content-ai::filament.keyword.keyword_group_source_auto') }}
                            </span>
                            @if ($group['is_locked'])
                                <span class="cluster-tag cluster-tag--planned">{{ __('seo-content-ai::filament.keyword.keyword_group_locked') }}</span>
                            @endif
                            <span class="text-gray-500">
                                {{ __('seo-content-ai::filament.keyword.keyword_group_member_count', ['count' => (int) $group['member_count']]) }}
                            </span>
                            <span class="text-gray-500">
                                {{ __('seo-content-ai::filament.keyword.keyword_group_topic_count', ['count' => (int) ($group['topic_count'] ?? 0)]) }}
                            </span>
                        </div>
                        @if (($group['representative_phrase'] ?? '') !== '')
                            <div class="mt-1 text-xs text-gray-500">
                                {{ __('seo-content-ai::filament.keyword.keyword_group_representative') }}:
                                {{ $group['representative_phrase'] }}
                            </div>
                        @endif
                    </div>
                    @if ($canMutate)
                        <div class="keyword-group-actions">
                            <button
                                type="button"
                                class="keyword-group-action keyword-group-action--recheck"
                                wire:click="recheckGroup({{ $groupId }})"
                                wire:loading.attr="disabled"
                                wire:target="recheckGroup, renameGroup({{ $groupId }}), deleteGroup"
                                :disabled="saving"
                                title="{{ __('seo-content-ai::filament.keyword.keyword_group_recheck_hint') }}"
                                aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_recheck_hint') }}"
                                data-group-recheck="{{ $groupId }}"
                            >
                                <svg
                                    class="keyword-group-action__icon animate-spin"
                                    wire:loading
                                    wire:target="recheckGroup({{ $groupId }})"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                <svg
                                    class="keyword-group-action__icon"
                                    wire:loading.remove
                                    wire:target="recheckGroup({{ $groupId }})"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span wire:loading.remove wire:target="recheckGroup({{ $groupId }})">
                                    {{ __('seo-content-ai::filament.keyword.keyword_group_recheck') }}
                                </span>
                                <span wire:loading wire:target="recheckGroup({{ $groupId }})">
                                    {{ __('seo-content-ai::filament.keyword.keyword_group_recheck_loading') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                class="keyword-group-action keyword-group-action--lock"
                                wire:click="toggleLock({{ $groupId }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleLock({{ $groupId }}), renameGroup({{ $groupId }}), recheckGroup, deleteGroup"
                                :disabled="saving"
                            >
                                @if ($group['is_locked'])
                                    <svg class="keyword-group-action__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                                    </svg>
                                    <span>{{ __('seo-content-ai::filament.keyword.keyword_group_unlock') }}</span>
                                @else
                                    <svg class="keyword-group-action__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                    <span>{{ __('seo-content-ai::filament.keyword.keyword_group_lock') }}</span>
                                @endif
                            </button>
                            <button
                                type="button"
                                class="keyword-group-action keyword-group-action--delete"
                                @click="confirmDelete()"
                                wire:loading.attr="disabled"
                                wire:target="recheckGroup, deleteGroup, renameGroup({{ $groupId }})"
                                :disabled="saving"
                                title="{{ __('seo-content-ai::filament.keyword.keyword_group_delete') }}"
                                aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_delete') }}"
                                data-group-delete="{{ $groupId }}"
                            >
                                <svg class="keyword-group-action__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <div
                    class="mt-3 space-y-3"
                    @if ($canMutate)
                        wire:loading.class="pointer-events-none opacity-60"
                        wire:target="renameGroup({{ $groupId }}), recheckGroup, deleteGroup({{ $groupId }})"
                        x-bind:class="saving ? 'pointer-events-none opacity-60' : ''"
                    @endif
                >
                    @if ($canMutate)
                        @php $renameSuggestions = $this->semanticSuggestions[$groupId] ?? []; @endphp
                        <div
                            class="relative max-w-md"
                            wire:key="group-search-{{ $groupId }}-{{ md5(json_encode($renameSuggestions)) }}"
                            x-data="{
                                q: '',
                                results: @js($renameSuggestions),
                                open: {{ $renameSuggestions !== [] ? 'true' : 'false' }},
                                loading: false,
                                async search() {
                                    this.loading = true;
                                    try {
                                        this.results = await $wire.searchUnassignedKeywords(this.q, {{ $groupId }});
                                        this.open = true;
                                    } finally {
                                        this.loading = false;
                                    }
                                },
                                async pick(id) {
                                    this.open = false;
                                    this.q = '';
                                    this.results = [];
                                    await $wire.addKeywordToGroup({{ $groupId }}, id);
                                }
                            }"
                        >
                            <input
                                type="search"
                                x-model="q"
                                @input.debounce.300ms="search()"
                                @focus="if ((q || '').trim() !== '') search()"
                                @keydown.enter.prevent="search()"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950"
                                placeholder="{{ __('seo-content-ai::filament.keyword.keyword_group_search_unassigned') }}"
                                wire:loading.attr="disabled"
                                wire:target="renameGroup({{ $groupId }}), recheckGroup, deleteGroup"
                            />
                            <div
                                class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow dark:border-gray-700 dark:bg-gray-900"
                                x-show="open && (results.length || loading || (q || '').trim() !== '')"
                                x-cloak
                                @click.outside="open = false"
                            >
                                <template x-if="loading">
                                    <div class="px-3 py-2 text-xs text-gray-400">…</div>
                                </template>
                                <template x-for="row in results" :key="row.keyword_id">
                                    <button
                                        type="button"
                                        class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                        @click="pick(row.keyword_id)"
                                        x-text="row.phrase"
                                    ></button>
                                </template>
                                <div
                                    class="px-3 py-2 text-xs text-gray-400"
                                    x-show="!loading && (q || '').trim() !== '' && !results.length"
                                >
                                    {{ __('seo-content-ai::filament.keyword.keyword_group_search_empty') }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div
                        class="keyword-group-member-chips"
                        wire:loading.class="opacity-50"
                        wire:target="loadMoreMembers({{ $groupId }}), addKeywordToGroup({{ $groupId }}), removeKeywordFromGroup, setTopicCandidateOverride, toggleTopicCandidate, renameGroup({{ $groupId }}), recheckGroup, deleteGroup"
                    >
                        @forelse ($members as $member)
                            @php
                                $memberKey = (int) ($member['keyword_id'] ?? 0);
                                $hasFocus = (bool) ($member['has_focus_article'] ?? false);
                                $override = array_key_exists('topic_candidate_override', $member)
                                    ? $member['topic_candidate_override']
                                    : null;
                                $effective = array_key_exists('effective_topic_candidate', $member)
                                    ? (bool) $member['effective_topic_candidate']
                                    : (bool) ($member['is_topic_candidate'] ?? true);
                                $chipState = $override === false
                                    ? 'blocked'
                                    : ($override === true
                                        ? 'force-allow'
                                        : ($hasFocus ? 'auto-eligible' : 'no-focus'));
                                $chipTitle = match ($chipState) {
                                    'blocked' => __('seo-content-ai::filament.keyword.keyword_group_topic_status_blocked'),
                                    'force-allow' => __('seo-content-ai::filament.keyword.keyword_group_topic_status_force_allow'),
                                    'auto-eligible' => __('seo-content-ai::filament.keyword.keyword_group_topic_status_auto_eligible'),
                                    default => __('seo-content-ai::filament.keyword.keyword_group_topic_status_no_focus'),
                                };
                            @endphp
                            <span
                                wire:key="keyword-group-member-{{ $groupId }}-{{ $memberKey }}"
                                @class([
                                    'keyword-group-member-chip',
                                    'keyword-group-member-chip--topic-blocked' => $chipState === 'blocked',
                                    'keyword-group-member-chip--no-focus' => $chipState === 'no-focus',
                                    'keyword-group-member-chip--force-allow' => $chipState === 'force-allow',
                                ])
                                data-topic-candidate="{{ $effective ? '1' : '0' }}"
                                data-topic-override="{{ $override === null ? 'auto' : ($override ? 'allow' : 'block') }}"
                                data-has-focus="{{ $hasFocus ? '1' : '0' }}"
                                title="{{ $chipTitle }}"
                            >
                                <span class="keyword-group-member-chip__label">{{ $member['phrase'] }}</span>
                                @if ($canMutate)
                                    @if ($chipState === 'auto-eligible')
                                        <button
                                            type="button"
                                            class="keyword-group-member-chip__topic"
                                            wire:click="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'block')"
                                            wire:loading.attr="disabled"
                                            wire:target="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'block'), recheckGroup"
                                            title="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_block') }}"
                                            aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_block') }}"
                                        >⊘</button>
                                    @elseif ($chipState === 'no-focus')
                                        <button
                                            type="button"
                                            class="keyword-group-member-chip__topic keyword-group-member-chip__topic--force"
                                            wire:click="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'allow')"
                                            wire:loading.attr="disabled"
                                            wire:target="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'allow'), recheckGroup"
                                            title="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_force_allow') }}"
                                            aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_force_allow') }}"
                                        >+Topic</button>
                                    @elseif ($chipState === 'force-allow')
                                        <button
                                            type="button"
                                            class="keyword-group-member-chip__topic keyword-group-member-chip__topic--force-on"
                                            wire:click="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'auto')"
                                            wire:loading.attr="disabled"
                                            wire:target="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'auto'), recheckGroup"
                                            title="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_clear_force') }}"
                                            aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_clear_force') }}"
                                        >✓Topic</button>
                                    @else
                                        <button
                                            type="button"
                                            class="keyword-group-member-chip__topic"
                                            wire:click="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'auto')"
                                            wire:loading.attr="disabled"
                                            wire:target="setTopicCandidateOverride({{ $groupId }}, {{ $memberKey }}, 'auto'), recheckGroup"
                                            title="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_restore_auto') }}"
                                            aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_topic_restore_auto') }}"
                                        >↺</button>
                                    @endif
                                    <button
                                        type="button"
                                        class="keyword-group-member-chip__remove"
                                        wire:click="removeKeywordFromGroup({{ $groupId }}, {{ $memberKey }})"
                                        wire:loading.attr="disabled"
                                        wire:target="removeKeywordFromGroup({{ $groupId }}, {{ $memberKey }}), recheckGroup"
                                        aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_remove') }}"
                                    >×</button>
                                @endif
                            </span>
                        @empty
                            <span class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.keyword_group_members_empty') }}</span>
                        @endforelse
                    </div>

                    @if ($hasMore)
                        <button
                            type="button"
                            class="text-sm font-medium text-primary-600 disabled:opacity-50"
                            wire:click="loadMoreMembers({{ $groupId }})"
                            wire:loading.attr="disabled"
                            wire:target="loadMoreMembers({{ $groupId }})"
                        >
                            {{ __('seo-content-ai::filament.keyword.keyword_group_show_more') }}
                        </button>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-sm text-gray-500">
                @if (trim((string) $this->newGroupName) !== '')
                    {{ __('seo-content-ai::filament.keyword.keyword_group_search_matches_empty', [
                        'query' => trim((string) $this->newGroupName),
                    ]) }}
                @else
                    {{ __('seo-content-ai::filament.keyword.keyword_group_empty') }}
                @endif
            </p>
        @endforelse
        </div>

        @if ($groups->hasPages())
            <div class="pt-2">
                {{ $groups->links() }}
            </div>
        @endif
        @endif
    </div>
</x-filament-panels::page>
