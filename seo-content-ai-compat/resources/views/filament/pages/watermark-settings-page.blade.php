<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
            <div class="flex flex-wrap items-center gap-3">
                @unless ($this->hasLockedGlobalSite())
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="wm-batch-site">
                        Domain (watermark belongs to this domain):
                    </label>
                    <x-select
                        id="wm-batch-site"
                        wire:model.live="siteId"
                        class="text-sm rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white min-w-[220px]"
                    >
                        <option value="">-- Select domain --</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </x-select>
                @else
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Domain:</span>
                    <span class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white min-w-[220px] px-3 py-2">
                        {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                    </span>
                @endunless
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Watermark design is managed in
                <a
                    href="{{ \Omnichannel\Addons\Media\Filament\Pages\WatermarkEditor::getUrl(['siteId' => $siteId]) }}"
                    class="text-primary-600 hover:underline"
                >
                    Watermark design suite
                </a>
                (per domain). This page runs batch watermark and image optimization.
            </p>
            @unless ($siteId)
                <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                    Select a domain to process images for the correct site.
                </p>
            @endunless
            @if ($siteId)
                @unless ($this->hasConfiguredDesign())
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                        No saved watermark design for this domain yet. Open the design suite and save a design before batch watermarking.
                    </p>
                @endunless
                <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Batch apply</p>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="batchApplyWatermark"
                            class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        />
                        <span>
                            <strong>Watermark</strong> — apply copyright mark from saved design
                        </span>
                    </label>
                    <p class="text-xs text-gray-500 pl-6">
                        Always <strong>optimize images</strong> (resize, WebP conversion from "Image optimization settings").
                        @if (! $batchApplyWatermark)
                            Only optimize files that are <strong>not .webp</strong>.
                        @else
                            Optimize after watermarking (.webp files are only watermarked, not reconverted).
                        @endif
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-photo"
                            wire:click="applyBatchToCurrentSite"
                            wire:confirm="Process all local and WordPress images for this domain? This may take a few minutes."
                            wire:loading.attr="disabled"
                            wire:target="applyBatchToCurrentSite"
                        >
                            <span wire:loading.remove wire:target="applyBatchToCurrentSite">Apply to all images</span>
                            <span wire:loading wire:target="applyBatchToCurrentSite">Processing…</span>
                        </x-filament::button>
                        <a
                            href="{{ \Omnichannel\Addons\Media\Filament\Pages\ImageOptimizationSettings::getUrl(['siteId' => $siteId]) }}"
                            class="text-xs text-primary-600 hover:underline"
                        >
                            Configure WebP optimization
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
