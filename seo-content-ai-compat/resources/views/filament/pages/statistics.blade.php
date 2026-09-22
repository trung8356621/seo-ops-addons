{{-- Statistics content canvas — shell unchanged. CSS via SeoServiceProvider STYLES_AFTER. --}}
@php
    $tab = (string) ($tab ?? 'domain');
    $domainData = is_array($domainData ?? null) ? $domainData : null;
    $userData = is_array($userData ?? null) ? $userData : null;
    $sites = $sites ?? collect();
    $monthOptions = is_array($monthOptions ?? null) ? $monthOptions : [];
    $userOptions = is_array($userOptions ?? null) ? $userOptions : [];
@endphp

<div
    class="ops-statistics"
    wire:loading.class="ops-statistics--loading"
>
    <div class="ops-statistics__header">
        <h1 class="ops-statistics__title">{{ __('seo-content-ai::filament.statistics.title') }}</h1>
        <p class="ops-statistics__subtitle">{{ __('seo-content-ai::filament.statistics.subtitle') }}</p>
    </div>

    <div class="ops-statistics__utility">
        <div class="ops-statistics__tabs" role="tablist">
            <button
                type="button"
                role="tab"
                class="ops-statistics__tab {{ $tab === 'domain' ? 'is-active' : '' }}"
                wire:click="$set('tab', 'domain')"
                wire:loading.attr="disabled"
            >
                {{ __('seo-content-ai::filament.statistics.tab_domain') }}
            </button>
            <button
                type="button"
                role="tab"
                class="ops-statistics__tab {{ $tab === 'user' ? 'is-active' : '' }}"
                wire:click="$set('tab', 'user')"
                wire:loading.attr="disabled"
            >
                {{ __('seo-content-ai::filament.statistics.tab_user') }}
            </button>
        </div>

        <div class="ops-statistics__filters">
            @if ($tab === 'domain')
                <label class="ops-statistics__filter">
                    <span class="ops-statistics__filter-label">{{ __('seo-content-ai::filament.statistics.filter_domain') }}</span>
                    <x-select wire:model.live="filterSiteId" class="ops-statistics__select">
                        <option value="">{{ __('seo-content-ai::filament.statistics.filter_all_domains') }}</option>
                        @foreach ($sites as $site)
                            <option value="{{ (int) $site->getKey() }}">{{ $site->domain }}</option>
                        @endforeach
                    </x-select>
                </label>
            @else
                <label class="ops-statistics__filter">
                    <span class="ops-statistics__filter-label">{{ __('seo-content-ai::filament.statistics.filter_user') }}</span>
                    <x-select wire:model.live="filterUserId" class="ops-statistics__select">
                        <option value="">{{ __('seo-content-ai::filament.statistics.filter_all_users') }}</option>
                        @foreach ($userOptions as $uid => $uname)
                            <option value="{{ (int) $uid }}">{{ $uname }}</option>
                        @endforeach
                    </x-select>
                </label>
            @endif

            <label class="ops-statistics__filter">
                <span class="ops-statistics__filter-label">{{ __('seo-content-ai::filament.statistics.filter_period') }}</span>
                <x-select wire:model.live="month" class="ops-statistics__select">
                    @foreach ($monthOptions as $option)
                        <option value="{{ $option['value'] ?? '' }}">{{ __('seo-content-ai::filament.statistics.month_prefix', ['month' => $option['label'] ?? '']) }}</option>
                    @endforeach
                </x-select>
            </label>

            @if ($tab === 'domain')
                <label class="ops-statistics__compare">
                    <input type="checkbox" wire:model.live="compare" class="ops-statistics__compare-input" />
                    <span>{{ __('seo-content-ai::filament.statistics.filter_compare') }}</span>
                </label>

                <button type="button" class="ops-statistics__export" disabled title="{{ __('seo-content-ai::filament.statistics.export_deferred') }}">
                    {{ __('seo-content-ai::filament.statistics.export') }}
                </button>
            @endif
        </div>
    </div>

    <div wire:loading.flex class="ops-statistics__loading" wire:target="tab,filterSiteId,filterUserId,month,compare,selectDomain,selectUser">
        <x-filament::loading-indicator class="h-5 w-5" />
        <span>{{ __('seo-content-ai::filament.statistics.loading') }}</span>
    </div>

    <div wire:loading.remove wire:target="tab,filterSiteId,filterUserId,month,compare,selectDomain,selectUser">
        @if ($tab === 'domain')
            @include('seo-content-ai::filament.pages.statistics.domain-tab', ['data' => $domainData])
        @else
            @include('seo-content-ai::filament.pages.statistics.user-tab', ['data' => $userData])
        @endif
    </div>
</div>
