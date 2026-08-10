@php
    $rows = is_array($rows ?? null) ? $rows : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.all_domains_team_heading')"
        :description="__('seo-content-ai::filament.dashboard.all_domains_team_description')"
        icon="heroicon-o-users"
    >
        @if($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.all_domains_team_empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pe-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_member') }}</th>
                            <th class="py-2 ps-3 text-end font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_optimized') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-3 pe-3 align-top">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $row['name'] ?? '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['email'] ?? '' }}</div>
                                </td>
                                <td class="py-3 ps-3 align-top text-end font-semibold text-gray-950 dark:text-white">
                                    {{ number_format((int) ($row['optimized_articles'] ?? 0)) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
