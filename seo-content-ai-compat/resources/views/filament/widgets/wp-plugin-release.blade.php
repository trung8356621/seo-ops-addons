@php
    $rows = is_array($rows ?? null) ? $rows : [];
    $latestVersion = filled($latest_version ?? null) ? (string) $latest_version : null;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.wp_plugin.heading')"
        :description="$latestVersion
            ? __('seo-content-ai::filament.wp_plugin.all_domains_description', ['version' => $latestVersion])
            : __('seo-content-ai::filament.wp_plugin.all_domains_description_no_release')"
        icon="heroicon-o-puzzle-piece"
    >
        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.wp_plugin.all_domains_empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pe-3 font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.dashboard.all_domains_col_domain') }}
                            </th>
                            <th class="py-2 px-3 font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.wp_plugin.version') }}
                            </th>
                            <th class="py-2 px-3 font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.wp_plugin.status') }}
                            </th>
                            <th class="py-2 ps-3 text-end font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.wp_plugin.action') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr wire:key="wp-plugin-domain-{{ $row['id'] ?? $loop->index }}">
                                <td class="py-3 pe-3 align-top font-medium text-gray-950 dark:text-white">
                                    {{ $row['domain'] ?? '—' }}
                                </td>
                                <td class="py-3 px-3 align-top text-gray-950 dark:text-white">
                                    <span class="font-semibold">{{ $row['installed_label'] ?? '—' }}</span>
                                    @if ($latestVersion && ($row['status'] ?? '') === 'needs_update')
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('seo-content-ai::filament.wp_plugin.latest_available', ['version' => $latestVersion]) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-3 align-top">
                                    <x-filament::badge :color="$row['status_color'] ?? 'gray'">
                                        {{ $row['status_label'] ?? '—' }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-3 ps-3 align-top text-end">
                                    <x-filament::button
                                        tag="a"
                                        size="sm"
                                        color="{{ ($row['status'] ?? '') === 'needs_update' ? 'primary' : 'gray' }}"
                                        :href="$row['settings_url'] ?? '#'"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        icon="heroicon-o-arrow-top-right-on-square"
                                    >
                                        {{ $row['action_label'] ?? __('seo-content-ai::filament.wp_plugin.action_check_on_wp') }}
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.wp_plugin.all_domains_manual_hint') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
