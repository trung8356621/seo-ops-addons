@php
    /** @var array<string, mixed>|null $summary */
    $pillarPhrase = is_array($summary) ? (string) ($summary['pillar_phrase'] ?? '') : '';
@endphp

<div
    class="topic-cluster-builder rounded-xl border border-indigo-100 bg-indigo-50/40 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/5"
    x-data="{ open: true }"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('seo-content-ai::filament.keyword.cluster_builder_heading') }}
            </h3>
            @if ($pillarPhrase !== '')
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.cluster_builder_pillar_hint', ['phrase' => $pillarPhrase]) }}
                </p>
            @endif
        </div>
    </div>

    <div class="relative mt-3">
        <input
            type="search"
            wire:model.live.debounce.400ms="childSearchQuery"
            wire:loading.attr="disabled"
            wire:target="childSearchQuery, attachChildKeyword"
            placeholder="{{ __('seo-content-ai::filament.keyword.cluster_child_search_placeholder') }}"
            class="topic-cluster-builder-input"
        />

        <div wire:loading wire:target="childSearchQuery" class="topic-cluster-builder-loading">
            {{ __('seo-content-ai::filament.keyword.cluster_searching') }}
        </div>

        @php $childResults = $this->getChildSearchResults(); @endphp
        @if ($childSearchQuery !== '' && mb_strlen(trim($childSearchQuery)) >= 2)
            <ul
                class="topic-cluster-search-results"
                x-show="true"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
            >
                @forelse ($childResults as $result)
                    <li wire:key="cluster-child-result-{{ $result['id'] }}">
                        <button
                            type="button"
                            wire:click="attachChildKeyword({{ (int) $result['id'] }})"
                            wire:loading.attr="disabled"
                            wire:target="attachChildKeyword"
                            class="topic-cluster-search-result"
                        >
                            <span wire:loading.remove wire:target="attachChildKeyword">{{ $result['phrase'] }}</span>
                            <span wire:loading wire:target="attachChildKeyword">{{ __('seo-content-ai::filament.keyword.cluster_saving') }}</span>
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
