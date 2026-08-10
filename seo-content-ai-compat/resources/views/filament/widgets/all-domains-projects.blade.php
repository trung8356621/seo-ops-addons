@php
    $rows = is_array($rows ?? null) ? $rows : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.all_domains_projects_heading')"
        :description="__('seo-content-ai::filament.dashboard.all_domains_projects_description')"
        icon="heroicon-o-rectangle-stack"
    >
        @if($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.all_domains_projects_empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pe-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_project') }}</th>
                            <th class="py-2 px-3 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_progress') }}</th>
                            <th class="py-2 ps-3 text-end font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.all_domains_col_ratio') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($rows as $row)
                            @php
                                $synced = (int) ($row['synced_tasks'] ?? 0);
                                $total = (int) ($row['total_tasks'] ?? 0);
                            @endphp
                            <tr>
                                <td class="py-3 pe-3 align-top">
                                    <a
                                        href="{{ $row['edit_url'] ?? '#' }}"
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row['name'] ?? '—' }}
                                    </a>
                                </td>
                                <td class="py-3 px-3 align-top text-gray-700 dark:text-gray-200">
                                    @php
                                        $domain = trim((string) ($row['domain'] ?? ''));
                                        $domainUrl = $row['domain_url'] ?? null;
                                    @endphp
                                    @if($domain !== '' && filled($domainUrl))
                                        <a
                                            href="{{ $domainUrl }}"
                                            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            {{ $domain }}
                                        </a>
                                    @elseif($domain !== '')
                                        {{ $domain }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3 ps-3 align-top text-end text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.dashboard.all_domains_ratio', ['done' => $synced, 'total' => $total]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
