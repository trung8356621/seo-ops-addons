@php
    $modalSearchResults = $this->getModalSearchResults();
@endphp

<div
    x-data="{
        clusterDraft: @entangle('clusterDraft').live,
        modalOpen: @entangle('clusterModalOpen').live,
        removeDraftItem(id) {
            this.clusterDraft = this.clusterDraft.filter(item => Number(item.id) !== Number(id))
        },
    }"
    x-cloak
>
    <template x-teleport="body">
        <div
            x-show="modalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="topic-cluster-modal-backdrop"
            @click.self="modalOpen = false; $wire.closeClusterModal()"
            @keydown.escape.window="modalOpen = false; $wire.closeClusterModal()"
        >
            <div
                class="topic-cluster-modal-panel"
                @click.stop
                x-show="modalOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                role="dialog"
                aria-modal="true"
                aria-labelledby="topic-cluster-modal-title"
            >
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-4 dark:border-white/10">
                    <div>
                        <h2 id="topic-cluster-modal-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.keyword.cluster_builder_heading') }}
                        </h2>
                        @if ($selectedPillar !== null)
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.keyword.cluster_builder_pillar_hint', ['phrase' => $selectedPillar['phrase']]) }}
                            </p>
                        @endif
                    </div>
                    <button
                        type="button"
                        class="topic-cluster-modal-close"
                        @click="modalOpen = false; $wire.closeClusterModal()"
                        aria-label="{{ __('seo-content-ai::filament.keyword.cluster_close_modal') }}"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-4 space-y-4">
                    @if ($isScanningSuggestions)
                        <div wire:init="loadReverseSuggestions" class="topic-cluster-scan-skeleton animate-pulse rounded-xl border border-indigo-100 bg-indigo-50/70 px-4 py-8 text-center dark:border-indigo-500/20 dark:bg-indigo-500/10">
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-200">
                                {{ __('seo-content-ai::filament.keyword.cluster_scanning_suggestions') }}
                            </p>
                        </div>
                    @else
                        <div>
                            <p class="topic-cluster-builder-label">
                                {{ __('seo-content-ai::filament.keyword.cluster_draft_heading') }}
                            </p>

                            <div class="topic-cluster-draft-tags min-h-[3rem] rounded-xl border border-gray-200 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-gray-900/50">
                                <template x-if="clusterDraft.length === 0">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.keyword.cluster_draft_empty') }}
                                    </p>
                                </template>

                                <div class="flex flex-wrap gap-2">
                                    <template x-for="item in clusterDraft" :key="item.id">
                                        <span class="topic-cluster-draft-tag">
                                            <span x-text="item.phrase"></span>
                                            <button
                                                type="button"
                                                class="topic-cluster-draft-tag__remove"
                                                @click="removeDraftItem(item.id)"
                                                :aria-label="'{{ __('seo-content-ai::filament.keyword.cluster_remove_draft_item') }}'"
                                            >
                                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                            </button>
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div class="relative">
                            <label class="topic-cluster-builder-label" for="topic-cluster-modal-search">
                                {{ __('seo-content-ai::filament.keyword.cluster_modal_search_label') }}
                            </label>
                            <input
                                id="topic-cluster-modal-search"
                                type="text"
                                wire:model.live.debounce.300ms="modalSearchQuery"
                                class="topic-cluster-builder-input"
                                placeholder="{{ __('seo-content-ai::filament.keyword.cluster_child_search_placeholder') }}"
                            />

                            <div wire:loading wire:target="modalSearchQuery" class="topic-cluster-builder-loading">
                                {{ __('seo-content-ai::filament.keyword.cluster_searching') }}
                            </div>

                            @if (mb_strlen(trim($modalSearchQuery)) >= 2)
                                <div class="topic-cluster-search-results">
                                    @forelse ($modalSearchResults as $result)
                                        <button
                                            type="button"
                                            wire:click="addKeywordToDraft({{ (int) $result['id'] }})"
                                            class="topic-cluster-search-result"
                                        >
                                            {{ $result['phrase'] }}
                                        </button>
                                    @empty
                                        <p class="topic-cluster-search-empty">
                                            {{ __('seo-content-ai::filament.keyword.cluster_search_no_results') }}
                                        </p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                    <button
                        type="button"
                        class="topic-cluster-pillar-btn"
                        @click="modalOpen = false; $wire.closeClusterModal()"
                    >
                        {{ __('seo-content-ai::filament.keyword.cluster_cancel') }}
                    </button>

                    @if ($canMutate)
                        <button
                            type="button"
                            wire:click="saveClusterRelationships"
                            wire:loading.attr="disabled"
                            @disabled($isScanningSuggestions)
                            class="topic-cluster-save-btn"
                        >
                            <span wire:loading.remove wire:target="saveClusterRelationships">
                                {{ __('seo-content-ai::filament.keyword.cluster_save_relationships') }}
                            </span>
                            <span wire:loading wire:target="saveClusterRelationships">
                                {{ __('seo-content-ai::filament.keyword.cluster_saving') }}
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
