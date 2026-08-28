@php
    $periodLabel = $gscState['period_label'] ?? '';
    $periodKey = $gscState['period_key'] ?? '';
    $lastSyncedAt = $gscState['last_synced_at'] ?? null;
    $hasData = ($gscState['has_data'] ?? false) === true;
    $monthOptions = $gscState['month_options'] ?? [];
    $canGoNext = ($gscState['can_go_next_month'] ?? false) === true;
@endphp

<section class="performance-hub-gsc-month-header">
    <div class="performance-hub-gsc-month-header__top">
        <div>
            <h2 class="performance-hub-gsc-month-header__title">{{ __('seo-content-ai::filament.api_connections.provider_gsc') }}</h2>
            <p class="performance-hub-gsc-month-header__subtitle">
                @if ($hasData && $lastSyncedAt)
                    {{ __('seo-content-ai::filament.performance_hub.gsc_month_synced_at', [
                        'month' => $periodLabel,
                        'time' => \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($lastSyncedAt) ?? $lastSyncedAt,
                    ]) }}
                @else
                    {{ __('seo-content-ai::filament.performance_hub.gsc_month_no_data', ['month' => $periodLabel]) }}
                @endif
            </p>
        </div>

        <div class="performance-hub-gsc-month-header__actions">
            <button
                type="button"
                wire:click="openGscMcpDrawer"
                class="performance-hub-action-btn performance-hub-action-btn--secondary"
            >
                {{ __('seo-content-ai::filament.performance_hub.view_gsc_mcp') }}
            </button>
        </div>
    </div>

    <div class="performance-hub-gsc-month-nav">
        <button
            type="button"
            wire:click="previousGscMonth"
            wire:loading.attr="disabled"
            wire:target="previousGscMonth,setGscMonth,nextGscMonth"
            class="performance-hub-gsc-month-nav__btn"
            aria-label="{{ __('seo-content-ai::filament.performance_hub.gsc_month_prev') }}"
        >
            ←
        </button>

        <x-select
            wire:model.live="gscMonth"
            class="performance-hub-gsc-month-nav__select"
            aria-label="{{ __('seo-content-ai::filament.performance_hub.gsc_month_select') }}"
        >
            @foreach ($monthOptions as $option)
                <option value="{{ $option['key'] }}">
                    {{ __('seo-content-ai::filament.performance_hub.gsc_month_option', ['label' => $option['label']]) }}
                </option>
            @endforeach
        </x-select>

        <button
            type="button"
            wire:click="nextGscMonth"
            wire:loading.attr="disabled"
            wire:target="nextGscMonth,setGscMonth,previousGscMonth"
            @disabled(! $canGoNext)
            class="performance-hub-gsc-month-nav__btn"
            aria-label="{{ __('seo-content-ai::filament.performance_hub.gsc_month_next') }}"
        >
            →
        </button>
    </div>
</section>

@include('seo-content-ai::seo.performance-hub.partials.gsc-mcp-drawer')
