@php
    $rowsModel = $rowsModel ?? null;
    $colsModel = $colsModel ?? null;
    $enableModel = $enableModel ?? null;
    $rows = $rows ?? 3;
    $cols = $cols ?? 2;
    $enabled = $enabled ?? true;
    $showEnableToggle = $showEnableToggle ?? false;
    $showButton = $showButton ?? false;
    $buttonLabel = $buttonLabel ?? null;
    $buttonDisabled = $buttonDisabled ?? false;
    $buttonWireClick = $buttonWireClick ?? null;
    $buttonAlpineClick = $buttonAlpineClick ?? null;
    $buttonLoadingTarget = $buttonLoadingTarget ?? null;
    $hint = $hint ?? null;
    $compact = $compact ?? false;

    $rowsLabel = __('seo-content-ai::filament.media_tools.split_rows');
    $colsLabel = __('seo-content-ai::filament.media_tools.split_columns');
    $splitLabel = $buttonLabel ?? __('seo-content-ai::filament.media_tools.split_image');
    $enableLabel = __('seo-content-ai::filament.prompt.post_processing.split_enable');
    $wrapperClass = 'seo-media-quick-split' . ($compact ? ' is-compact' : '');
@endphp

<div class="{{ $wrapperClass }}">
    @if ($showEnableToggle && filled($enableModel))
        <label class="seo-media-quick-split__enable">
            <input
                type="checkbox"
                wire:model.live="{{ $enableModel }}"
                @disabled(! $enabled)
            />
            <span>{{ $enableLabel }}</span>
        </label>
    @endif

    <div class="seo-media-quick-split__row splitter-row">
        <label>
            {{ $rowsLabel }}
            @if (filled($rowsModel))
                <input
                    type="number"
                    min="1"
                    max="12"
                    wire:model.blur="{{ $rowsModel }}"
                    @disabled(! $enabled)
                />
            @else
                <input type="number" min="1" max="12" value="{{ (int) $rows }}" disabled />
            @endif
        </label>

        <label>
            {{ $colsLabel }}
            @if (filled($colsModel))
                <input
                    type="number"
                    min="1"
                    max="12"
                    wire:model.blur="{{ $colsModel }}"
                    @disabled(! $enabled)
                />
            @else
                <input type="number" min="1" max="12" value="{{ (int) $cols }}" disabled />
            @endif
        </label>

        @if ($showButton)
            <button
                type="button"
                class="seo-media-quick-split__submit btn-primary"
                @if ($buttonAlpineClick) x-on:click="{{ $buttonAlpineClick }}" @endif
                @if ($buttonWireClick) wire:click="{{ $buttonWireClick }}" @endif
                @if ($buttonLoadingTarget) wire:loading.attr="disabled" wire:target="{{ $buttonLoadingTarget }}" @endif
                @disabled($buttonDisabled)
            >
                {{ $splitLabel }}
            </button>
        @endif
    </div>

    @if (filled($hint))
        <p class="seo-media-quick-split__hint hint">{{ $hint }}</p>
    @endif
</div>
