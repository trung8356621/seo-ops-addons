@php
    $widthModel = $widthModel ?? null;
    $heightModel = $heightModel ?? null;
    $enableModel = $enableModel ?? null;
    $showEnableToggle = $showEnableToggle ?? false;
    $showSelection = $showSelection ?? false;
    $selectedCountAlpine = $selectedCountAlpine ?? null;
    $selectedCount = $selectedCount ?? 0;
    $showClearSelection = $showClearSelection ?? false;
    $clearSelectionAlpine = $clearSelectionAlpine ?? null;
    $showSubmit = $showSubmit ?? true;
    $submitLabel = $submitLabel ?? null;
    $submitDisabledAlpine = $submitDisabledAlpine ?? null;
    $submitAlpineClick = $submitAlpineClick ?? null;
    $submitWireClick = $submitWireClick ?? null;
    $submitLoadingTargets = $submitLoadingTargets ?? null;
    $hint = $hint ?? null;
    $enabled = $enabled ?? true;
    $asBar = $asBar ?? true;

    $resizeLabel = $submitLabel ?? __('seo-content-ai::filament.media_tools.resize_images');
    $enableLabel = __('seo-content-ai::filament.prompt.post_processing.resize_enable');
    $selectedLabel = __('seo-content-ai::filament.media_tools.selected');
    $clearLabel = __('seo-content-ai::filament.media_tools.clear_selection');
    $widthLabel = __('seo-content-ai::filament.media_tools.width');
    $heightLabel = __('seo-content-ai::filament.media_tools.height');
    $wrapperClass = $asBar ? 'seo-media-library-resize-bar' : 'seo-media-quick-resize';
@endphp

<div class="{{ $wrapperClass }}">
    @if ($showEnableToggle && filled($enableModel))
        <label class="seo-media-quick-resize__enable">
            <input
                type="checkbox"
                wire:model.live="{{ $enableModel }}"
                @disabled(! $enabled)
            />
            <span>{{ $enableLabel }}</span>
        </label>
    @endif

    @if ($showSelection)
        <div class="seo-media-library-resize-bar__left">
            <span class="seo-media-library-resize-bar__label">
                {{ $selectedLabel }}:
                <strong @if ($selectedCountAlpine) x-text="{{ $selectedCountAlpine }}" @endif>
                    @unless ($selectedCountAlpine){{ (int) $selectedCount }}@endunless
                </strong>
            </span>

            @if ($showClearSelection && $clearSelectionAlpine)
                <button
                    type="button"
                    class="seo-media-library-resize-bar__link"
                    x-on:click="{{ $clearSelectionAlpine }}"
                    x-show="{{ $selectedCountAlpine ?? 'selectedCount' }} > 0"
                    x-cloak
                >
                    {{ $clearLabel }}
                </button>
            @endif
        </div>
    @endif

    <div class="seo-media-library-resize-bar__controls">
        <label class="seo-media-library-resize-field">
            <span>{{ $widthLabel }}</span>
            @if (filled($widthModel))
                <input
                    type="number"
                    min="1"
                    class="seo-media-library-resize-input"
                    placeholder="px"
                    wire:model.blur="{{ $widthModel }}"
                    @if ($submitLoadingTargets) wire:loading.attr="disabled" wire:target="{{ $submitLoadingTargets }}" @endif
                    @disabled(! $enabled)
                />
            @else
                <input type="number" min="1" class="seo-media-library-resize-input" placeholder="px" disabled />
            @endif
        </label>

        <span class="seo-media-library-resize-times">×</span>

        <label class="seo-media-library-resize-field">
            <span>{{ $heightLabel }}</span>
            @if (filled($heightModel))
                <input
                    type="number"
                    min="1"
                    class="seo-media-library-resize-input"
                    placeholder="px"
                    wire:model.blur="{{ $heightModel }}"
                    @if ($submitLoadingTargets) wire:loading.attr="disabled" wire:target="{{ $submitLoadingTargets }}" @endif
                    @disabled(! $enabled)
                />
            @else
                <input type="number" min="1" class="seo-media-library-resize-input" placeholder="px" disabled />
            @endif
        </label>

        @if ($showSubmit)
            <button
                type="button"
                class="seo-media-library-resize-submit"
                @if ($submitAlpineClick) x-on:click="{{ $submitAlpineClick }}" @endif
                @if ($submitWireClick) wire:click="{{ $submitWireClick }}" @endif
                @if ($submitLoadingTargets) wire:loading.attr="disabled" wire:target="{{ $submitLoadingTargets }}" @endif
                @if ($submitDisabledAlpine) x-bind:disabled="{{ $submitDisabledAlpine }}" @endif
            >
                @if ($submitLoadingTargets)
                    <span wire:loading.remove wire:target="{{ $submitLoadingTargets }}">{{ $resizeLabel }}</span>
                    <span wire:loading wire:target="{{ $submitLoadingTargets }}">{{ __('seo-content-ai::filament.media_tools.resizing') }}</span>
                @else
                    {{ $resizeLabel }}
                @endif
            </button>
        @endif
    </div>

    @if (filled($hint))
        <p class="seo-media-library-resize-hint">{{ $hint }}</p>
    @endif
</div>
