<x-filament-panels::page>
    @viteReactRefresh
    @vite('addons/media/resources/js/watermark-editor-page.jsx')

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 mb-4">
        <div class="flex flex-wrap items-center gap-3">
            @unless ($this->hasLockedGlobalSite())
                <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="wm-design-site">
                    Domain (watermark belongs to this domain):
                </label>
                <x-select
                    id="wm-design-site"
                    wire:model.live="siteId"
                    class="text-sm rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white min-w-[220px]"
                >
                    <option value="">-- Select domain --</option>
                    @foreach ($this->sites as $site)
                        <option value="{{ $site->id }}">{{ $site->domain }}</option>
                    @endforeach
                </x-select>
            @else
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Domain:
                </span>
                <span class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white min-w-[220px] px-3 py-2">
                    {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                </span>
            @endunless
            @if ($siteId)
                <span class="text-xs text-gray-500">
                    Design config is saved per domain ·
                    <a
                        href="{{ \Omnichannel\Addons\Media\Filament\Pages\WatermarkSettingsPage::getUrl(['siteId' => $siteId]) }}"
                        class="text-primary-600 hover:underline"
                    >
                        Batch apply
                    </a>
                </span>
            @endif
        </div>
        @unless ($siteId)
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                Select a domain to load design presets and save watermark to the correct domain.
            </p>
        @endunless
    </div>

    <div
        id="seo-watermark-editor-root"
        class="seo-watermark-editor-host"
        data-site-id="{{ $siteId ?? '' }}"
        data-site-domain="{{ $siteId ? ($this->sites->firstWhere('id', (int) $siteId)?->domain ?? '') : '' }}"
        data-image-url="{{ $imageUrl ?? '' }}"
        data-image-id="{{ $imageId ?? '' }}"
        data-back-url="{{ \Omnichannel\Addons\Media\Filament\Pages\WatermarkSettingsPage::getUrl(['siteId' => $siteId]) }}"
        data-initial-config='@json($this->getInitialDesignConfig())'
        data-media-samples='@json($this->getMediaSamples())'
    ></div>
</x-filament-panels::page>
