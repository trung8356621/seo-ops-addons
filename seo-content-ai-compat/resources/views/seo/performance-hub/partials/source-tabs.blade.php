<nav class="performance-hub-source-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.source_tabs_label') }}">
    @foreach ($this->availableSourceTabs as $sourceTab)
        <button
            type="button"
            wire:click="setDataSource('{{ $sourceTab['key'] }}')"
            wire:loading.attr="disabled"
            wire:target="setDataSource"
            @class([
                'performance-hub-source-tab',
                'is-active' => $dataSource === $sourceTab['key'],
                'is-inactive-provider' => ($sourceTab['configured'] ?? false) && ! ($sourceTab['active'] ?? true),
            ])
            aria-selected="{{ $dataSource === $sourceTab['key'] ? 'true' : 'false' }}"
        >
            {{ $sourceTab['label'] ?? $sourceTab['key'] }}
        </button>
    @endforeach
</nav>
