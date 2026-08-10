@php
    $groupOptions = $this->rankGroupOptions;
    $metricsState = $metricsState ?? [];
@endphp

<div class="performance-hub-toolbar performance-hub-toolbar--inline">
    <div class="performance-hub-toolbar__field performance-hub-toolbar__field--group">
        <label for="perf-metrics-rank-group">{{ __('seo-content-ai::filament.rank_group.selector_label') }}</label>
        <x-select id="perf-metrics-rank-group" wire:model.live="rankGroupId" class="performance-hub-select performance-hub-select--wide">
            <option value="">{{ __('seo-content-ai::filament.rank_group.select_placeholder') }}</option>
            @foreach ($groupOptions as $option)
                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
            @endforeach
        </x-select>
    </div>
</div>
