@php
    $pillar = $domainCard['pillar'] ?? [];
    $children = $domainCard['children'] ?? [];
@endphp

<article class="topic-cluster-domain-card rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950/40">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200">
                {{ $domainCard['domain_initials'] ?? '?' }}
            </span>
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $domainCard['domain'] ?? '' }}
                </h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.cluster_domain_card_hint') }}
                </p>
            </div>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-gray-100 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-gray-900/40">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.keyword.cluster_pillar_state') }}
        </p>

        @if (($pillar['has_article'] ?? false) === true)
            <div class="mt-2">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $pillar['title'] ?? __('seo-content-ai::filament.keyword.cluster_link_detected') }}
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if (! empty($pillar['url']))
                        <a
                            href="{{ $pillar['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-300"
                        >
                            {{ __('seo-content-ai::filament.keyword.cluster_view_article') }}
                        </a>
                    @endif
                    @if (! empty($pillar['edit_url']))
                        <a
                            href="{{ $pillar['edit_url'] }}"
                            class="text-xs font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300"
                        >
                            {{ __('seo-content-ai::filament.keyword.cluster_edit_article') }}
                        </a>
                    @endif
                </div>
            </div>
        @else
            <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                    {{ __('seo-content-ai::filament.keyword.cluster_missing_pillar') }}
                </span>
                @if ($canMutate && ! empty($domainCard['site_id']) && ! empty($selectedKeywordId))
                    <button
                        type="button"
                        wire:click="createPillarDraft({{ (int) $domainCard['site_id'] }}, {{ (int) $selectedKeywordId }})"
                        class="topic-cluster-pillar-btn topic-cluster-pillar-btn--primary"
                    >
                        {{ __('seo-content-ai::filament.keyword.cluster_create_draft') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    <div class="mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.keyword.cluster_sub_articles_heading') }}
        </p>

        @if ($children === [])
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.cluster_no_children') }}
            </p>
        @else
            <ul class="mt-2 space-y-2">
                @foreach ($children as $child)
                    <li class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $child['phrase'] ?? '' }}
                            </p>
                            @if (! empty($child['source_title']))
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $child['source_title'] }}
                                </p>
                            @endif
                        </div>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => ($child['linked'] ?? false) === true,
                            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ($child['linked'] ?? false) !== true,
                        ])>
                            {{ ($child['linked'] ?? false) === true
                                ? __('seo-content-ai::filament.keyword.cluster_link_detected')
                                : __('seo-content-ai::filament.keyword.cluster_unlinked') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</article>
