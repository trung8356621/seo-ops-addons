<div x-show="tab === 'serp_intelligence'" x-cloak class="space-y-4" x-data="{ serpTab: 'overview' }">
    <x-filament::section>
        <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.serp_heading') }}</x-slot>
        <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.serp_subheading') }}</p>
    </x-filament::section>

    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach ([
            'overview' => 'Overview',
            'queries' => 'Queries',
            'snapshots' => 'Snapshots',
            'cluster_evidence' => 'Cluster Evidence',
            'content_gaps' => 'Content Gaps',
            'competitors' => 'Competitors',
            'operations' => 'Operations',
        ] as $sub => $label)
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-medium"
                :class="serpTab === '{{ $sub }}'
                    ? 'bg-primary-600 text-white'
                    : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'"
                x-on:click="serpTab = '{{ $sub }}'"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div x-show="serpTab === 'overview'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Overview</x-slot>
            <p class="text-sm text-gray-500">SERP Intelligence Phase 4 — query scope, snapshots, overlap validation, content gaps. Placeholder cards; data loads in later phases.</p>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'queries'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Queries</x-slot>
            <p class="text-sm text-gray-500">Tracked SERP queries per keyword cluster (srpq_* refs).</p>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'snapshots'" x-cloak class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Snapshots</x-slot>
            <p class="mb-3 text-sm text-gray-500">Manual import preview — no persist until Import command wired with query ref.</p>
            <div class="space-y-3">
                <x-filament::input.wrapper>
                    <textarea
                        wire:model="serpImportPayload"
                        rows="8"
                        class="fi-input block w-full border-none bg-transparent p-0 font-mono text-xs focus:ring-0"
                        placeholder='[{"url":"https://example.com","title":"Example","type":"organic","position":1}]'
                    ></textarea>
                </x-filament::input.wrapper>
                <div class="flex flex-wrap items-center gap-3">
                    <x-select wire:model="serpImportFormat" class="text-sm">
                        <option value="json">JSON</option>
                        <option value="csv">CSV</option>
                    </x-select>
                    <x-filament::button
                        type="button"
                        wire:click="previewSerpImport"
                        wire:loading.attr="disabled"
                        wire:target="previewSerpImport"
                    >
                        <span wire:loading.remove wire:target="previewSerpImport">{{ __('seo-content-ai::filament.keyword_intelligence.serp_preview_import') }}</span>
                        <span wire:loading wire:target="previewSerpImport" class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('seo-content-ai::filament.keyword_intelligence.serp_previewing') }}
                        </span>
                    </x-filament::button>
                </div>
                @if ($serpImportPreview)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-700 dark:bg-gray-900">
                        <pre class="whitespace-pre-wrap break-words">{{ json_encode($serpImportPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'cluster_evidence'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Cluster Evidence</x-slot>
            <p class="text-sm text-gray-500">SERP overlap validation suggestions (keep / split / outlier) — read-only until reviewed.</p>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'content_gaps'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Content Gaps</x-slot>
            <p class="text-sm text-gray-500">Competitor vs own page evidence gaps (FAQ, schema, media, headings).</p>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'competitors'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Competitors</x-slot>
            <p class="text-sm text-gray-500">Domain frequency and competitor summary from latest snapshots.</p>
        </x-filament::section>
    </div>

    <div x-show="serpTab === 'operations'" x-cloak>
        <x-filament::section>
            <x-slot name="heading">Operations</x-slot>
            <p class="text-sm text-gray-500">Collect / analyze operations with serp-collection locks and idempotent import checksums.</p>
        </x-filament::section>
    </div>
</div>
