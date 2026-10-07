@php
    $groups = $this->getGroupsPaginator();
    $unassignedCount = $this->getUnassignedCount();
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
                >
                    <svg wire:loading wire:target="refreshSemanticGroups" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span>{{ __('seo-content-ai::filament.keyword.keyword_group_refresh') }}</span>
                </button>
            @endif
        </header>

        @if ($canMutate)
            <form class="flex flex-wrap items-center gap-2" wire:submit="createGroup">
                <input
                    type="text"
                    wire:model="newGroupName"
                    class="w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                    placeholder="{{ __('seo-content-ai::filament.keyword.keyword_group_name') }}"
                />
                <button
                    type="submit"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium disabled:opacity-50 dark:border-gray-700"
                    wire:loading.attr="disabled"
                    wire:target="createGroup"
                >
                    <span wire:loading.remove wire:target="createGroup">{{ __('seo-content-ai::filament.keyword.keyword_group_create') }}</span>
                    <span wire:loading wire:target="createGroup">…</span>
                </button>
            </form>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-800 dark:bg-gray-900">
            <span class="font-medium text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.keyword.keyword_group_unassigned_summary', ['count' => number_format($unassignedCount)]) }}
            </span>
        </div>

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
                                    wire:target="renameGroup({{ $groupId }}), recheckGroup({{ $groupId }})"
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
                        </div>
                        @if (($group['representative_phrase'] ?? '') !== '')
                            <div class="mt-1 text-xs text-gray-500">
                                {{ __('seo-content-ai::filament.keyword.keyword_group_representative') }}:
                                {{ $group['representative_phrase'] }}
                            </div>
                        @endif
                    </div>
                    @if ($canMutate)
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2 py-1 text-xs disabled:opacity-50 dark:border-gray-700"
                                wire:click="recheckGroup({{ $groupId }})"
                                wire:loading.attr="disabled"
                                wire:target="recheckGroup({{ $groupId }}), renameGroup({{ $groupId }})"
                                :disabled="saving"
                                title="{{ __('seo-content-ai::filament.keyword.keyword_group_recheck_hint') }}"
                                aria-label="{{ __('seo-content-ai::filament.keyword.keyword_group_recheck_hint') }}"
                                data-group-recheck="{{ $groupId }}"
                            >
                                <svg
                                    class="h-3.5 w-3.5 animate-spin"
                                    wire:loading
                                    wire:target="recheckGroup({{ $groupId }})"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
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
                                class="rounded-lg border border-gray-300 px-2 py-1 text-xs disabled:opacity-50 dark:border-gray-700"
                                wire:click="toggleLock({{ $groupId }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleLock({{ $groupId }}), renameGroup({{ $groupId }}), recheckGroup({{ $groupId }})"
                                :disabled="saving"
                            >
                                {{ $group['is_locked']
                                    ? __('seo-content-ai::filament.keyword.keyword_group_unlock')
                                    : __('seo-content-ai::filament.keyword.keyword_group_lock') }}
                            </button>
                        </div>
                    @endif
                </div>

                <div
                    class="mt-3 space-y-3"
                    @if ($canMutate)
                        wire:loading.class="pointer-events-none opacity-60"
                        wire:target="renameGroup({{ $groupId }}), recheckGroup({{ $groupId }})"
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
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950"
                                placeholder="{{ __('seo-content-ai::filament.keyword.keyword_group_search_unassigned') }}"
                                wire:loading.attr="disabled"
                                wire:target="renameGroup({{ $groupId }}), recheckGroup({{ $groupId }})"
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
                        wire:target="loadMoreMembers({{ $groupId }}), addKeywordToGroup({{ $groupId }}), removeKeywordFromGroup, toggleTopicCandidate, renameGroup({{ $groupId }}), recheckGroup({{ $groupId }})"
                    >
                        @forelse ($members as $member)
                            @php
                                $topicCandidate = array_key_exists('is_topic_candidate', $member)
                                    ? (bool) $member['is_topic_candidate']
                                    : true;
                                $memberKey = (int) ($member['keyword_id'] ?? 0);
                            @endphp
                            <span
                                wire:key="kg-member-{{ $groupId }}-{{ $memberKey }}-{{ $topicCandidate ? '1' : '0' }}"
                                @class([
                                    'keyword-group-member-chip',
                                    'keyword-group-member-chip--topic-blocked' => ! $topicCandidate,
                                ])
                                data-topic-candidate="{{ $topicCandidate ? '1' : '0' }}"
                            >
                                @if (! $topicCandidate)
                                    <span class="keyword-group-member-chip__blocked-mark" aria-hidden="true">⊘</span>
                                @endif
                                <span class="keyword-group-member-chip__label">{{ $member['phrase'] }}</span>
                                @if ($canMutate)
                                    <button
                                        type="button"
                                        class="keyword-group-member-chip__topic"
                                        wire:click="toggleTopicCandidate({{ $groupId }}, {{ $memberKey }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleTopicCandidate({{ $groupId }}, {{ $memberKey }})"
                                        title="{{ $topicCandidate
                                            ? __('seo-content-ai::filament.keyword.keyword_group_topic_block')
                                            : __('seo-content-ai::filament.keyword.keyword_group_topic_allow') }}"
                                        aria-label="{{ $topicCandidate
                                            ? __('seo-content-ai::filament.keyword.keyword_group_topic_block')
                                            : __('seo-content-ai::filament.keyword.keyword_group_topic_allow') }}"
                                        aria-pressed="{{ $topicCandidate ? 'false' : 'true' }}"
                                    >⊘</button>
                                    <button
                                        type="button"
                                        class="keyword-group-member-chip__remove"
                                        wire:click="removeKeywordFromGroup({{ $groupId }}, {{ $memberKey }})"
                                        wire:loading.attr="disabled"
                                        wire:target="removeKeywordFromGroup({{ $groupId }}, {{ $memberKey }})"
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
            <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.keyword_group_empty') }}</p>
        @endforelse

        @if ($groups->hasPages())
            <div class="pt-2">
                {{ $groups->links() }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
