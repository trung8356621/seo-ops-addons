@php
    $hasSite = (bool) ($has_site ?? false);
    $overview = is_array($overview ?? null) ? $overview : [];
    $totalKeywords = (int) ($overview['total_keywords'] ?? 0);
    $topicCount = (int) ($overview['topic_count'] ?? 0);
    $topics = is_array($overview['topics'] ?? null) ? $overview['topics'] : [];
    $keywords = is_array($overview['keywords'] ?? null) ? $overview['keywords'] : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-key">
        <x-slot name="heading">
            <div class="flex w-full items-center justify-between gap-3">
                <span>{{ __('seo-content-ai::filament.dashboard.stat_keywords') }}</span>
                @if($hasSite)
                    <span class="text-lg font-semibold tabular-nums text-primary-600 dark:text-primary-400">
                        {{ number_format($totalKeywords) }}
                    </span>
                @endif
            </div>
        </x-slot>

        @if(! $hasSite)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.select_domain_hint') }}
            </p>
        @else
            <div
                x-data="{ tab: 'topics' }"
                class="space-y-3"
            >
                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-white/10 dark:bg-white/5">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                        :class="tab === 'topics'
                            ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white'
                            : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                        @click="tab = 'topics'"
                    >
                        {{ __('seo-content-ai::filament.dashboard.keyword_overview_tab_topics') }}
                        <span class="ml-1 tabular-nums text-gray-400 dark:text-gray-500">{{ number_format($topicCount) }}</span>
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                        :class="tab === 'keywords'
                            ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white'
                            : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                        @click="tab = 'keywords'"
                    >
                        {{ __('seo-content-ai::filament.dashboard.keyword_overview_tab_keywords') }}
                        <span class="ml-1 tabular-nums text-gray-400 dark:text-gray-500">{{ number_format($totalKeywords) }}</span>
                    </button>
                </div>

                <div x-show="tab === 'topics'" x-cloak>
                    @if($topics === [])
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.dashboard.keyword_overview_topics_empty') }}
                        </p>
                    @else
                        <ul class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($topics as $row)
                                @php
                                    $label = (string) ($row['label'] ?? '');
                                    $linkCount = (int) ($row['internal_link_count'] ?? 0);
                                    $keywordCount = (int) ($row['keyword_count'] ?? 0);
                                    $url = (string) ($row['url'] ?? '');
                                @endphp
                                <li class="flex items-start justify-between gap-3 py-2">
                                    <div class="min-w-0">
                                        @if($url !== '')
                                            <a
                                                href="{{ $url }}"
                                                class="block truncate text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                                title="{{ $label }}"
                                            >{{ $label }}</a>
                                        @else
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $label }}">{{ $label }}</span>
                                        @endif
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('seo-content-ai::filament.dashboard.keyword_overview_topic_keywords', ['count' => $keywordCount]) }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-600 dark:text-gray-300">
                                        {{ __('seo-content-ai::filament.dashboard.keyword_overview_links', ['count' => number_format($linkCount)]) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div x-show="tab === 'keywords'" x-cloak>
                    @if($keywords === [])
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.dashboard.keyword_overview_keywords_empty') }}
                        </p>
                    @else
                        <ul class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($keywords as $row)
                                @php
                                    $phrase = (string) ($row['phrase'] ?? '');
                                    $linkCount = (int) ($row['internal_link_count'] ?? 0);
                                    $isFocus = (bool) ($row['is_focus'] ?? false);
                                @endphp
                                <li class="flex items-start justify-between gap-3 py-2">
                                    <div class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $phrase }}">{{ $phrase }}</span>
                                        @if($isFocus)
                                            <p class="mt-0.5 text-xs text-emerald-600 dark:text-emerald-400">
                                                {{ __('seo-content-ai::filament.dashboard.keyword_overview_focus') }}
                                            </p>
                                        @endif
                                    </div>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-600 dark:text-gray-300">
                                        {{ __('seo-content-ai::filament.dashboard.keyword_overview_links', ['count' => number_format($linkCount)]) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
