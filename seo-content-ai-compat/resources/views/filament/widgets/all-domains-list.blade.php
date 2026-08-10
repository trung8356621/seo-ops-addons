@php
    $rows = is_array($rows ?? null) ? $rows : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.all_domains_list_heading')"
        :description="__('seo-content-ai::filament.dashboard.all_domains_list_description')"
        icon="heroicon-o-globe-alt"
    >
        @if($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.all_domains_list_empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pe-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_domain') }}</th>
                            <th class="py-2 px-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_score_distribution') }}</th>
                            <th class="py-2 ps-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_worst_article') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-3 pe-3 align-top">
                                    <a
                                        href="{{ $row['overview_url'] ?? '#' }}"
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row['domain'] ?? '—' }}
                                    </a>
                                </td>
                                <td class="py-3 px-3 align-top">
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse(($row['segments'] ?? []) as $segment)
                                            @php
                                                $filterUrl = $segment['filter_url'] ?? null;
                                                $badgeClass = 'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold text-white';
                                                $badgeStyle = 'background-color: ' . ($segment['color'] ?? '#64748b');
                                                $badgeLabel = ($segment['label'] ?? '') . ': ' . number_format((int) ($segment['count'] ?? 0));
                                            @endphp
                                            @if(filled($filterUrl))
                                                <a
                                                    href="{{ $filterUrl }}"
                                                    class="{{ $badgeClass }} hover:opacity-90"
                                                    style="{{ $badgeStyle }}"
                                                    title="{{ __('seo-content-ai::filament.dashboard.all_domains_score_filter_hint', ['domain' => $row['domain'] ?? '']) }}"
                                                >
                                                    {{ $badgeLabel }}
                                                </a>
                                            @else
                                                <span
                                                    class="{{ $badgeClass }}"
                                                    style="{{ $badgeStyle }}"
                                                >
                                                    {{ $badgeLabel }}
                                                </span>
                                            @endif
                                        @empty
                                            <span class="text-xs text-gray-500 dark:text-gray-400">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="py-3 ps-3 align-top">
                                    @if(! empty($row['all_excellent']))
                                        <x-filament::badge color="success">
                                            {{ __('seo-content-ai::filament.dashboard.all_domains_all_excellent') }}
                                        </x-filament::badge>
                                    @elseif(is_array($row['worst_article'] ?? null))
                                        <a
                                            href="{{ $row['worst_article']['edit_url'] ?? '#' }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium text-danger-600 hover:underline dark:text-danger-400"
                                            title="{{ __('seo-content-ai::filament.dashboard.all_domains_worst_score', ['score' => number_format((float) ($row['worst_article']['score'] ?? 0), 1)]) }}"
                                        >
                                            {{ $row['worst_article']['title'] ?? '—' }}
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
