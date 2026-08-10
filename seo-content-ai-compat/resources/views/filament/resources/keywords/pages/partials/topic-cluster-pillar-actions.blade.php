@php
    /** @var array<string, mixed>|null $summary */
@endphp

@if ($summary !== null)
    <div
        class="border-b border-gray-100 px-4 py-3 dark:border-white/10"
        x-data="{ showParentPicker: @entangle('showAssignParentPicker') }"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.cluster_selected_keyword') }}
                </p>
                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                    {{ $summary['phrase'] ?? '—' }}
                </p>
            </div>

            <div class="flex flex-wrap gap-1.5">
                @if (! ($summary['is_pillar'] ?? false))
                    <button
                        type="button"
                        wire:click="markAsPillar"
                        wire:loading.attr="disabled"
                        wire:target="markAsPillar"
                        class="topic-cluster-pillar-btn topic-cluster-pillar-btn--primary"
                    >
                        <span wire:loading.remove wire:target="markAsPillar">⭐ {{ __('seo-content-ai::filament.keyword.cluster_mark_pillar') }}</span>
                        <span wire:loading wire:target="markAsPillar">{{ __('seo-content-ai::filament.keyword.cluster_saving') }}</span>
                    </button>
                @else
                    <span class="topic-cluster-pillar-badge">
                        ⭐ {{ __('seo-content-ai::filament.keyword.cluster_is_pillar') }}
                    </span>
                @endif

                <button
                    type="button"
                    wire:click="toggleAssignParentPicker"
                    class="topic-cluster-pillar-btn"
                    x-bind:class="showParentPicker ? 'topic-cluster-pillar-btn--active' : ''"
                >
                    {{ __('seo-content-ai::filament.keyword.cluster_assign_parent') }}
                </button>
            </div>
        </div>

        <div
            x-show="showParentPicker"
            x-collapse
            x-cloak
            class="mt-3"
        >
            <label class="topic-cluster-builder-label" for="cluster-parent-search">
                {{ __('seo-content-ai::filament.keyword.cluster_assign_parent_hint') }}
            </label>
            <div class="relative">
                <input
                    id="cluster-parent-search"
                    type="search"
                    wire:model.live.debounce.400ms="parentSearchQuery"
                    wire:loading.attr="disabled"
                    wire:target="parentSearchQuery, assignParentToSelected"
                    placeholder="{{ __('seo-content-ai::filament.keyword.cluster_parent_search_placeholder') }}"
                    class="topic-cluster-builder-input"
                />
                <div wire:loading wire:target="parentSearchQuery" class="topic-cluster-builder-loading">
                    {{ __('seo-content-ai::filament.keyword.cluster_searching') }}
                </div>

                @php $parentResults = $this->getParentSearchResults(); @endphp
                @if ($parentSearchQuery !== '' && mb_strlen(trim($parentSearchQuery)) >= 2)
                    <ul
                        class="topic-cluster-search-results"
                        x-show="true"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                    >
                        @forelse ($parentResults as $result)
                            <li wire:key="cluster-parent-result-{{ $result['id'] }}">
                                <button
                                    type="button"
                                    wire:click="assignParentToSelected({{ (int) $result['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="assignParentToSelected"
                                    class="topic-cluster-search-result"
                                >
                                    {{ $result['phrase'] }}
                                </button>
                            </li>
                        @empty
                            <li class="topic-cluster-search-empty">
                                {{ __('seo-content-ai::filament.keyword.cluster_search_no_results') }}
                            </li>
                        @endforelse
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endif
