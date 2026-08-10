<div
    class="performance-hub-gsc-intelligence mt-6 space-y-4"
    x-data="{ gscIntelTab: 'overview' }"
    x-cloak
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_heading') }}
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_hint') }}
            </p>
        </div>
    </div>

    <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_tabs_label') }}">
        @foreach ([
            'overview' => 'Overview',
            'queries' => 'Queries',
            'pages' => 'Pages',
            'opportunities' => 'Opportunities',
            'sync' => 'Sync',
        ] as $tabKey => $tabLabel)
            <button
                type="button"
                @click="gscIntelTab = '{{ $tabKey }}'"
                :class="gscIntelTab === '{{ $tabKey }}' ? 'performance-hub-tab is-active' : 'performance-hub-tab'"
                :aria-selected="gscIntelTab === '{{ $tabKey }}' ? 'true' : 'false'"
            >
                {{ $tabLabel }}
            </button>
        @endforeach
    </nav>

    <div x-show="gscIntelTab === 'overview'" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_overview_placeholder') }}
        </p>
    </div>

    <div x-show="gscIntelTab === 'queries'" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_queries_placeholder') }}
        </p>
    </div>

    <div x-show="gscIntelTab === 'pages'" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_pages_placeholder') }}
        </p>
    </div>

    <div x-show="gscIntelTab === 'opportunities'" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_opportunities_placeholder') }}
        </p>
    </div>

    <div x-show="gscIntelTab === 'sync'" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('seo-content-ai::filament.performance_hub.gsc_intelligence_sync_hint') }}
        </p>

        <div>
            <label for="gsc-import-csv" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('seo-content-ai::filament.performance_hub.gsc_import_csv_label') }}
            </label>
            <textarea
                id="gsc-import-csv"
                wire:model="gscImportCsv"
                rows="6"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-950"
                placeholder="date,query,page,country,device,search_appearance,clicks,impressions,ctr,position"
            ></textarea>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="previewGscImport"
                wire:loading.attr="disabled"
                wire:target="previewGscImport"
                class="performance-hub-primary-btn"
            >
                <span wire:loading.remove wire:target="previewGscImport">
                    {{ __('seo-content-ai::filament.performance_hub.gsc_import_preview') }}
                </span>
                <span wire:loading wire:target="previewGscImport" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ __('seo-content-ai::filament.performance_hub.gsc_import_previewing') }}
                </span>
            </button>
        </div>

        @if (is_array($gscImportPreview))
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <dt class="text-gray-500">{{ __('seo-content-ai::filament.performance_hub.gsc_import_valid') }}</dt>
                        <dd class="font-semibold">{{ (int) ($gscImportPreview['valid'] ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('seo-content-ai::filament.performance_hub.gsc_import_invalid') }}</dt>
                        <dd class="font-semibold">{{ (int) ($gscImportPreview['invalid'] ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('seo-content-ai::filament.performance_hub.gsc_import_duplicate') }}</dt>
                        <dd class="font-semibold">{{ (int) ($gscImportPreview['duplicate'] ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('seo-content-ai::filament.performance_hub.gsc_import_total') }}</dt>
                        <dd class="font-semibold">{{ (int) ($gscImportPreview['total'] ?? 0) }}</dd>
                    </div>
                </dl>
            </div>
        @endif
    </div>
</div>
