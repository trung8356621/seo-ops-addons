<section class="topic-cluster-pillars-full rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/40">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4 dark:border-white/10">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                {{ __('seo-content-ai::filament.keyword.cluster_tree_heading') }}
            </h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.cluster_pillar_list_hint') }}
            </p>
        </div>

        @if ($canMutate)
            <div class="flex flex-wrap items-center gap-2">
                @if ($showNewPillarInput)
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            type="text"
                            wire:model="newPillarPhrase"
                            wire:keydown.enter="createNewPillar"
                            class="topic-cluster-builder-input min-w-[14rem]"
                            placeholder="{{ __('seo-content-ai::filament.keyword.cluster_new_pillar_placeholder') }}"
                            autofocus
                        />
                        <button
                            type="button"
                            wire:click="createNewPillar"
                            wire:loading.attr="disabled"
                            class="topic-cluster-pillar-btn topic-cluster-pillar-btn--primary"
                        >
                            {{ __('seo-content-ai::filament.keyword.cluster_save_new_pillar') }}
                        </button>
                        <button
                            type="button"
                            wire:click="toggleNewPillarInput"
                            class="topic-cluster-pillar-btn"
                        >
                            {{ __('seo-content-ai::filament.keyword.cluster_cancel') }}
                        </button>
                    </div>
                @else
                    <button
                        type="button"
                        wire:click="toggleNewPillarInput"
                        class="topic-cluster-add-pillar-btn"
                    >
                        <span aria-hidden="true">➕</span>
                        {{ __('seo-content-ai::filament.keyword.cluster_add_new_pillar') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    @if ($pillars === [])
        <div class="mt-4 rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/15">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.cluster_tree_empty') }}
            </p>
        </div>
    @else
        <div class="topic-cluster-pillar-grid mt-4">
            @foreach ($pillars as $pillar)
                @php
                    $isSelected = (int) ($selectedKeywordId ?? 0) === (int) $pillar['id'];
                @endphp
                <button
                    type="button"
                    wire:click="openClusterBuilder({{ (int) $pillar['id'] }})"
                    @class([
                        'topic-cluster-pillar-card',
                        'is-selected' => $isSelected,
                    ])
                >
                    <div class="flex min-w-0 flex-1 items-start justify-between gap-3">
                        <div class="min-w-0 text-left">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $pillar['phrase'] }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @if (($pillar['volume'] ?? null) !== null)
                                    <span class="topic-cluster-meta-tag">Vol {{ number_format((int) $pillar['volume']) }}</span>
                                @endif
                                @if (($pillar['difficulty'] ?? null) !== null)
                                    <span class="topic-cluster-meta-tag">KD {{ (int) $pillar['difficulty'] }}</span>
                                @endif
                                <span class="topic-cluster-meta-tag topic-cluster-meta-tag--links">
                                    {{ (int) ($pillar['active_links_count'] ?? 0) }} {{ __('seo-content-ai::filament.keyword.cluster_links_short') }}
                                </span>
                                <span class="topic-cluster-meta-tag">
                                    {{ (int) ($pillar['children_count'] ?? 0) }} {{ __('seo-content-ai::filament.keyword.cluster_children_short') }}
                                </span>
                            </div>
                        </div>
                        <span class="topic-cluster-pillar-badge shrink-0">
                            {{ __('seo-content-ai::filament.keyword.cluster_is_pillar') }}
                        </span>
                    </div>
                </button>
            @endforeach
        </div>
    @endif
</section>
