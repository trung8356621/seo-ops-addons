@php
    $group = $rankState['group'] ?? null;
    $groupOptions = $this->rankGroupOptions;
    $capabilities = $this->rankProviderCapabilities;
    $allintitleSupported = ($capabilities['allintitle'] ?? false) === true;
    $volumeConfigured = ($capabilities['search_volume_configured'] ?? false) === true;
    $initialAllintitle = $runMetricsAllintitle && $allintitleSupported;
    $initialVolume = $runMetricsSearchVolume && $volumeConfigured;
@endphp

<div class="performance-hub-toolbar performance-hub-toolbar--inline">
    <div class="performance-hub-toolbar__field performance-hub-toolbar__field--group">
        <label for="perf-rank-group">{{ __('seo-content-ai::filament.rank_group.selector_label') }}</label>
        <x-select id="perf-rank-group" wire:model.live="rankGroupId" class="performance-hub-select performance-hub-select--wide">
            <option value="">{{ __('seo-content-ai::filament.rank_group.select_placeholder') }}</option>
            @foreach ($groupOptions as $option)
                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
            @endforeach
        </x-select>
    </div>

    <div class="performance-hub-run-menu" x-data="{ open: false, rank: @js($runMetricsRank), allintitle: @js($initialAllintitle), volume: @js($initialVolume) }">
        <button
            type="button"
            class="performance-hub-action-btn performance-hub-action-btn--compact"
            @click="open = !open"
            wire:loading.attr="disabled"
            wire:target="runKeywordRankCheck"
        >
            <span wire:loading.remove wire:target="runKeywordRankCheck">{{ __('seo-content-ai::filament.rank_group.run_group') }}</span>
            <span wire:loading wire:target="runKeywordRankCheck">{{ __('seo-content-ai::filament.performance_hub.running_rank_check') }}</span>
        </button>

        <div x-show="open" x-cloak @click.outside="open = false" class="performance-hub-run-popover">
            <p class="performance-hub-run-popover__title">{{ __('seo-content-ai::filament.performance_hub.run_metrics_title') }}</p>
            <label class="performance-hub-run-check">
                <input type="checkbox" x-model="rank" wire:model="runMetricsRank" />
                <span>{{ __('seo-content-ai::filament.performance_hub.run_metric_rank') }}</span>
            </label>
            <label @class(['performance-hub-run-check', 'is-disabled' => ! $allintitleSupported])>
                <input type="checkbox" x-model="allintitle" wire:model="runMetricsAllintitle" @disabled(! $allintitleSupported) />
                <span>{{ __('seo-content-ai::filament.performance_hub.run_metric_allintitle') }}</span>
                @if (! $allintitleSupported)
                    <span class="performance-hub-run-check__note">{{ __('seo-content-ai::filament.performance_hub.metric_not_supported') }}</span>
                @endif
            </label>
            <label @class(['performance-hub-run-check', 'is-disabled' => ! $volumeConfigured])>
                <input type="checkbox" x-model="volume" wire:model="runMetricsSearchVolume" @disabled(! $volumeConfigured) />
                <span>{{ __('seo-content-ai::filament.performance_hub.run_metric_volume') }}</span>
                @if (! $volumeConfigured)
                    <span class="performance-hub-run-check__note">{{ __('seo-content-ai::filament.performance_hub.volume_not_configured') }}</span>
                @endif
            </label>
            <button
                type="button"
                class="performance-hub-action-btn performance-hub-action-btn--compact performance-hub-action-btn--block"
                @click="open = false; $wire.runKeywordRankCheck()"
                wire:loading.attr="disabled"
                wire:target="runKeywordRankCheck"
            >{{ __('seo-content-ai::filament.performance_hub.run_all_available') }}</button>
        </div>
    </div>

    <div class="performance-hub-toolbar__menu" x-data="{ open: false }">
        <button type="button" class="performance-hub-icon-btn" @click="open = !open" aria-label="{{ __('seo-content-ai::filament.rank_group.manage_menu') }}">⋯</button>
        <div x-show="open" x-cloak @click.outside="open = false" class="performance-hub-toolbar__dropdown">
            <button type="button" @click="open = false; $dispatch('open-rank-group-modal', { groupId: null })">{{ __('seo-content-ai::filament.rank_group.create') }}</button>
            @if ($group)
                <button type="button" @click="open = false; $dispatch('open-rank-group-modal', { groupId: {{ (int) $group['id'] }} })">{{ __('seo-content-ai::filament.rank_group.edit') }}</button>
                <button type="button" wire:click="duplicateRankGroup({{ (int) $group['id'] }})" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.rank_group.duplicate') }}</button>
                <button type="button" wire:click="archiveRankGroup({{ (int) $group['id'] }})" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.rank_group.archive') }}</button>
                <button type="button" wire:click="deleteRankGroup({{ (int) $group['id'] }})" wire:confirm="{{ __('seo-content-ai::filament.rank_group.delete_confirm') }}" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.rank_group.delete') }}</button>
            @endif
        </div>
    </div>
</div>
