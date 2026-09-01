@php
    $hasSite = (bool) ($has_site ?? false);
    $sync = is_array($sync ?? null) ? $sync : [];
    $bridge = is_array($bridge ?? null) ? $bridge : [];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.sync_table_heading')"
        :description="$hasSite ? ($domain ?? '') : __('seo-content-ai::filament.dashboard.select_domain_hint')"
        icon="heroicon-o-server-stack"
    >
        @if(! $hasSite)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.sync_table_empty') }}
            </p>
        @else
            <dl class="divide-y divide-gray-200 dark:divide-white/10">
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.dashboard.sync_total') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((int) ($sync['total'] ?? 0)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_posts') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((int) ($sync['articles'] ?? 0)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_products') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((int) ($sync['products'] ?? 0)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_categories') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((int) ($sync['categories'] ?? 0)) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_product_categories') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((int) ($sync['product_categories'] ?? 0)) }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_bridge') }}</dt>
                    <dd class="text-right">
                        <x-filament::badge :color="$bridge['color'] ?? 'gray'">
                            {{ $bridge['label'] ?? '—' }}
                        </x-filament::badge>
                        @if(filled($bridge['detail'] ?? null))
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $bridge['detail'] }}</p>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_running_projects') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ (int) ($running_projects ?? 0) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2.5">
                    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.dashboard.sync_running_workflows') }}</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ (int) ($running_workflows ?? 0) }}</dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-200 pt-3 dark:border-white/10">
                <x-filament::button
                    tag="a"
                    size="sm"
                    color="gray"
                    :href="$domain_url"
                    icon="heroicon-o-globe-alt"
                >
                    {{ __('seo-content-ai::filament.dashboard.open_domain_overview') }}
                </x-filament::button>
                <x-filament::button
                    tag="a"
                    size="sm"
                    color="primary"
                    :href="$projects_url"
                    icon="heroicon-o-rectangle-stack"
                >
                    {{ __('seo-content-ai::filament.dashboard.open_content_projects') }}
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
