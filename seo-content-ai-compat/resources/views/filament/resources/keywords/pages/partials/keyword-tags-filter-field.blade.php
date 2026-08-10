@php
    /** @var list<string> $includeLabels */
    /** @var list<string> $excludeLabels */
    $includeLabels = is_array($includeLabels ?? null) ? $includeLabels : [];
    $excludeLabels = is_array($excludeLabels ?? null) ? $excludeLabels : [];
    $hasSelection = $includeLabels !== [] || $excludeLabels !== [];
@endphp

<div class="keyword-tags-filter-field">
    <div class="keyword-tags-filter-field__display">
        @if (! $hasSelection)
            <span class="keyword-tags-filter-field__placeholder">
                {{ __('seo-content-ai::filament.keyword.tags_filter_empty') }}
            </span>
        @else
            <div class="keyword-tags-filter-field__badges">
                @foreach ($includeLabels as $label)
                    <span class="keyword-tags-filter-field__badge keyword-tags-filter-field__badge--include">
                        {{ $label }}
                    </span>
                @endforeach
                @foreach ($excludeLabels as $label)
                    <span class="keyword-tags-filter-field__badge keyword-tags-filter-field__badge--exclude">
                        − {{ $label }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <button
        type="button"
        class="keyword-tags-filter-field__trigger ws-btn ws-btn--ghost"
        wire:click="mountAction('filterTags')"
    >
        <x-filament::icon icon="heroicon-m-tag" class="h-4 w-4" />
        {{ __('seo-content-ai::filament.keyword.tags_filter_choose') }}
    </button>
</div>
