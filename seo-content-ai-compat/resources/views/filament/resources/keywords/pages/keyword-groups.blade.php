@php
    $view = $this->getKeywordGroupView();
    $groups = $view['groups'];
    $unassigned = $view['unassigned'];
    $unassignedCount = (int) $view['unassigned_count'];
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

        <section
            id="keyword-group-unassigned"
            class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
            x-data="{ open: true }"
        >
            <button type="button" class="flex w-full items-center justify-between text-left" @click="open = !open">
                <span class="font-semibold text-gray-950 dark:text-white">
                    {{ __('seo-content-ai::filament.keyword.keyword_group_unassigned') }}
                </span>
                <span class="text-sm text-gray-500">{{ number_format($unassignedCount) }}</span>
            </button>
            <div class="mt-3 max-h-80 space-y-2 overflow-y-auto" x-show="open" x-cloak>
                @forelse ($unassigned as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>› {{ $row['phrase'] }}</span>
                        @if ($canMutate && $groups !== [])
                            <x-select
                                size="sm"
                                wrapClass="x-select-wrap x-select-wrap--sm max-w-xs"
                                wire:change="assignKeyword({{ (int) $row['keyword_id'] }}, $event.target.value)"
                                wire:loading.class="opacity-50 pointer-events-none"
                                wire:target="assignKeyword"
                            >
                                <option value="">{{ __('seo-content-ai::filament.keyword.keyword_group_move') }}</option>
                                @foreach ($groups as $target)
                                    <option value="{{ (int) $target['id'] }}">{{ $target['name'] }}</option>
                                @endforeach
                            </x-select>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.keyword_group_unassigned_empty') }}</p>
                @endforelse
            </div>
        </section>

        @forelse ($groups as $group)
            @php
                $groupId = (int) $group['id'];
                $focused = $focusGroupId === $groupId;
                $isManual = (bool) ($group['is_manual'] ?? false);
            @endphp
            <article
                id="keyword-group-{{ $groupId }}"
                class="rounded-xl border bg-white p-4 dark:bg-gray-900 {{ $focused ? 'border-primary-500' : 'border-gray-200 dark:border-gray-800' }}"
                x-data="{ open: {{ $focused ? 'true' : 'false' }} }"
                @if ($focused) x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <button type="button" class="min-w-0 text-left" @click="open = !open">
                        <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $group['name'] }}</div>
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
                    </button>
                    @if ($canMutate)
                        <div class="flex flex-wrap items-center gap-2" x-data='{ name: @json($group["name"]) }'>
                            <input type="text" x-model="name" class="w-48 rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-950" />
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-xs disabled:opacity-50 dark:border-gray-700"
                                wire:loading.attr="disabled"
                                wire:target="renameGroup"
                                @click="$wire.renameGroup({{ $groupId }}, name)"
                            >{{ __('seo-content-ai::filament.keyword.keyword_group_rename') }}</button>
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-xs disabled:opacity-50 dark:border-gray-700"
                                wire:click="toggleLock({{ $groupId }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleLock"
                            >
                                {{ $group['is_locked']
                                    ? __('seo-content-ai::filament.keyword.keyword_group_unlock')
                                    : __('seo-content-ai::filament.keyword.keyword_group_lock') }}
                            </button>
                        </div>
                    @endif
                </div>

                <div class="mt-3 max-h-80 space-y-2 overflow-y-auto" x-show="open" x-cloak>
                    @foreach ($group['members'] as $member)
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span>› {{ $member['phrase'] }}</span>
                            @if ($canMutate)
                                <x-select
                                    size="sm"
                                    wrapClass="x-select-wrap x-select-wrap--sm max-w-xs"
                                    wire:change="assignKeyword({{ (int) $member['keyword_id'] }}, $event.target.value)"
                                    wire:loading.class="opacity-50 pointer-events-none"
                                    wire:target="assignKeyword"
                                >
                                    <option value="{{ $groupId }}">{{ $group['name'] }}</option>
                                    <option value="0">{{ __('seo-content-ai::filament.keyword.keyword_group_unassigned') }}</option>
                                    @foreach ($groups as $target)
                                        @if ((int) $target['id'] !== $groupId)
                                            <option value="{{ (int) $target['id'] }}">{{ $target['name'] }}</option>
                                        @endif
                                    @endforeach
                                </x-select>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.keyword_group_empty') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
