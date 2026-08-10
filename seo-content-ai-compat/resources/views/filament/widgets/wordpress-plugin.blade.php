@php
    $meta = $metadata ?? [];
    $sections = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
    $description = (string) ($sections['description'] ?? '');
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.wp_plugin.heading')"
        :description="__('seo-content-ai::filament.wp_plugin.description')"
        icon="heroicon-o-puzzle-piece"
        compact
    >
        @if ($has_packages && $latest)
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0 flex-1">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2.5">
                        <div class="flex gap-2 w-6/12">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.wp_plugin.name') }}
                            </dt>
                            <dd class="mt-0.5 truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $meta['name'] ?? 'omi-seo-ai-bridge' }}
                            </dd>
                        </div>
                        <div class="flex gap-2 w-6/12">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.wp_plugin.version') }}
                            </dt>
                            <dd class="mt-0.5 text-sm font-semibold text-gray-950 dark:text-white">
                                <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                                    v{{ $latest['version'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex gap-2 w-6/12">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.wp_plugin.requires') }}
                            </dt>
                            <dd class="mt-0.5 text-sm text-gray-950 dark:text-white">
                                WP {{ $meta['requires'] ?? '—' }}
                                @if (filled($meta['tested'] ?? null))
                                    <span class="text-gray-500 dark:text-gray-400">· {{ $meta['tested'] }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex gap-2 w-6/12">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.wp_plugin.php') }}
                            </dt>
                            <dd class="mt-0.5 text-sm text-gray-950 dark:text-white">
                                {{ $meta['requires_php'] ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    @if ($description !== '' || filled($meta['last_updated'] ?? null))
                        <div class="mt-2 space-y-0.5 border-t border-gray-200 pt-2 dark:border-white/10">
                            @if ($description !== '')
                                <p class="line-clamp-2 text-xs text-gray-600 dark:text-gray-300">
                                    {{ $description }}
                                </p>
                            @endif
                            @if (filled($meta['last_updated'] ?? null))
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.last_updated') }}: {{ $meta['last_updated'] }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>

                
            </div>
            <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center lg:flex-col lg:items-end">
                    <x-filament::button
                        tag="a"
                        :href="url('/admin/wp-plugin-release?name=omi-seo-ai-bridge')"
                        icon="heroicon-o-arrow-up-tray"
                        color="gray"
                        size="sm"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.manage_releases') }}
                    </x-filament::button>

                    <x-filament::button
                        tag="a"
                        :href="route('seo.wp-plugin.download', ['version' => $latest['version']])"
                        icon="heroicon-o-arrow-down-tray"
                        color="primary"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.download_latest', ['version' => $latest['version']]) }}
                    </x-filament::button>

                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400 lg:justify-end">
                        <span>{{ $latest['size_label'] }}</span>
                        @if (filled($latest['modified_at'] ?? null))
                            <span>· {{ $latest['modified_at'] }}</span>
                        @endif
                        @if (count($older) > 0)
                            <x-filament::button
                                type="button"
                                color="gray"
                                size="xs"
                                :icon="$showOlderVersions ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down'"
                                wire:click="$toggle('showOlderVersions')"
                            >
                                {{ $showOlderVersions
                                    ? __('seo-content-ai::filament.wp_plugin.hide_older')
                                    : trans_choice('seo-content-ai::filament.wp_plugin.show_older', count($older), ['count' => count($older)]) }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @if ($showOlderVersions && count($older) > 0)
                <div class="mt-3 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-1.5 text-left text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.version') }}
                                </th>
                                <th class="px-3 py-1.5 text-left text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.file') }}
                                </th>
                                <th class="px-3 py-1.5 text-left text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.size') }}
                                </th>
                                <th class="px-3 py-1.5 text-right text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.wp_plugin.action') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                            @foreach ($older as $release)
                                <tr wire:key="wp-plugin-{{ $release['version'] }}">
                                    <td class="whitespace-nowrap px-3 py-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                        v{{ $release['version'] }}
                                    </td>
                                    <td class="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300">
                                        <code class="text-xs">{{ $release['filename'] }}</code>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $release['size_label'] }}
                                        @if (filled($release['modified_at'] ?? null))
                                            <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ $release['modified_at'] }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right">
                                        <x-filament::button
                                            tag="a"
                                            size="xs"
                                            color="gray"
                                            :href="route('seo.wp-plugin.download', ['version' => $release['version']])"
                                            icon="heroicon-o-arrow-down-tray"
                                        >
                                            {{ __('seo-content-ai::filament.wp_plugin.download') }}
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <dl class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-2.5">
                <div>
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.name') }}
                    </dt>
                    <dd class="mt-0.5 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $meta['name'] ?? 'omi-seo-ai-bridge' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.wp_plugin.version') }}
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">—</dd>
                </div>
            </dl>

            <x-filament::section
                compact
                class="mt-3 border-dashed"
            >
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.wp_plugin.no_packages') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.wp_plugin.no_packages_hint') }}
                </p>
                <div class="mt-3">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        :href="url('/admin/wp-plugin-release?name=omi-seo-ai-bridge')"
                        icon="heroicon-o-arrow-up-tray"
                    >
                        {{ __('seo-content-ai::filament.wp_plugin.manage_releases') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
