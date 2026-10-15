<section class="space-y-3">
    <header>
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('seo-content-ai::semantic_routing.industry_heading') }}</h2>
        <p class="text-sm text-gray-500">{{ __('seo-content-ai::semantic_routing.industry_intro') }}</p>
    </header>

    @if ($groups === [])
        <div class="rounded-lg border border-gray-200 p-4 text-sm text-gray-700 dark:border-white/10 dark:text-gray-200">
            <p>{{ __('seo-content-ai::semantic_routing.industry_empty') }}</p>
            @if ($industryContextUrl)
                <p class="mt-2">
                    <a href="{{ $industryContextUrl }}" class="text-primary-600 underline">{{ __('seo-content-ai::semantic_routing.industry_context_link') }}</a>
                </p>
            @endif
        </div>
    @else
        @if (is_array($provenance))
            <div class="flex flex-wrap gap-3 text-sm text-gray-700 dark:text-gray-200">
                <span><strong>{{ __('seo-content-ai::semantic_routing.industry_context') }}:</strong> {{ $provenance['industry_context_key'] ?? $industryContextKey }}</span>
                @if (isset($provenance['match_revision_id']))
                    <span><strong>{{ __('seo-content-ai::semantic_routing.revision') }}:</strong> #{{ $provenance['match_revision_id'] }}</span>
                @endif
                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs dark:bg-white/10">
                    {{ ($provenance['stale'] ?? false) ? __('seo-content-ai::semantic_routing.stale') : __('seo-content-ai::semantic_routing.fresh') }}
                </span>
            </div>
        @elseif ($industryContextKey)
            <p class="text-sm text-gray-600">{{ __('seo-content-ai::semantic_routing.industry_context') }}: {{ $industryContextKey }}</p>
        @endif

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2">{{ __('seo-content-ai::semantic_routing.col_group') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::semantic_routing.col_language') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::semantic_routing.col_enabled') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::semantic_routing.col_source') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr class="border-t border-gray-200 align-top dark:border-white/10">
                            <td class="px-3 py-2" colspan="4">
                                <details>
                                    <summary class="cursor-pointer list-none">
                                        <span class="grid grid-cols-1 gap-2 md:grid-cols-4">
                                            <span>
                                                <span class="font-medium">{{ $group['name'] }}</span>
                                                <span class="mt-0.5 block text-xs text-gray-500">{{ $group['group_type_label'] }}</span>
                                            </span>
                                            <span>{{ $group['language'] }}</span>
                                            <span>{{ ($group['enabled'] ?? false) ? __('seo-content-ai::semantic_routing.enabled') : __('seo-content-ai::semantic_routing.disabled') }}</span>
                                            <span class="text-xs text-gray-600">
                                                {{ $group['industry_context_key'] ?? '' }}
                                                @if ($group['revision'] !== null)
                                                    · #{{ $group['revision'] }}
                                                @endif
                                            </span>
                                        </span>
                                    </summary>
                                    <div class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-xs text-gray-700 dark:border-white/10 dark:text-gray-200">
                                        @if (($group['description'] ?? '') !== '')
                                            <p><strong>{{ __('seo-content-ai::semantic_routing.description') }}:</strong> {{ $group['description'] }}</p>
                                        @endif
                                        @if (($group['match_mode'] ?? null) !== null && $group['match_mode'] !== '')
                                            <p><strong>{{ __('seo-content-ai::semantic_routing.match_mode') }}:</strong> {{ $group['match_mode'] }}</p>
                                        @endif
                                        @if (($group['stale'] ?? false) === true)
                                            <p>{{ __('seo-content-ai::semantic_routing.stale') }}</p>
                                        @endif
                                        <p><strong>{{ __('seo-content-ai::semantic_routing.positive') }}:</strong> {{ ($group['positive_examples'] ?? []) === [] ? '—' : implode(', ', $group['positive_examples']) }}</p>
                                        <p><strong>{{ __('seo-content-ai::semantic_routing.negative') }}:</strong> {{ ($group['negative_examples'] ?? []) === [] ? '—' : implode(', ', $group['negative_examples']) }}</p>
                                        @if (($group['aliases'] ?? []) !== [])
                                            <p><strong>{{ __('seo-content-ai::semantic_routing.aliases') }}:</strong> {{ implode(', ', $group['aliases']) }}</p>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
