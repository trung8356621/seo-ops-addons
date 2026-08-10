@php
    $bands = [
        '' => __('seo-content-ai::filament.article_list.all_scores'),
        'poor' => __('seo-content-ai::filament.article_list.seo_score_poor'),
        'fair' => __('seo-content-ai::filament.article_list.seo_score_fair'),
        'good' => __('seo-content-ai::filament.article_list.seo_score_good'),
        'excellent' => __('seo-content-ai::filament.article_list.seo_score_excellent'),
    ];
@endphp

<div
    class="article-seo-header"
    x-data="{ filterOpen: false }"
    x-on:click.outside="filterOpen = false"
    onclick="event.stopPropagation()"
>
    <div class="article-seo-header__row">
        <span class="article-seo-header__title">{{ __('seo-content-ai::filament.article_list.seo_details') }}</span>
        <button
            type="button"
            class="article-seo-header__filter-btn"
            x-bind:class="{ 'is-active': ($wire.tableFilters?.seo_score_band?.value ?? '') !== '' }"
            title="{{ __('seo-content-ai::filament.article_list.seo_score_filter') }}"
            x-on:click.stop="filterOpen = !filterOpen"
            wire:loading.attr="disabled"
            wire:target="setSeoScoreBandFilter"
        >
            <span class="article-seo-header__filter-btn-inner" wire:loading.remove wire:target="setSeoScoreBandFilter">
                <x-filament::icon icon="heroicon-m-funnel" class="article-seo-header__filter-icon" />
            </span>
            <span class="article-seo-header__filter-btn-inner" wire:loading wire:target="setSeoScoreBandFilter">
                <x-filament::loading-indicator class="article-seo-header__filter-spinner" />
            </span>
        </button>
    </div>

    <div
        class="article-seo-header__menu"
        x-show="filterOpen"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:click.stop
    >
        @foreach ($bands as $value => $label)
            <button
                type="button"
                class="article-seo-header__menu-item"
                x-bind:class="{ 'is-active': ($wire.tableFilters?.seo_score_band?.value ?? '') === @js((string) $value) }"
                wire:click="setSeoScoreBandFilter(@js($value === '' ? null : $value))"
                wire:loading.attr="disabled"
                wire:target="setSeoScoreBandFilter"
                x-on:click="filterOpen = false"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>

@once
    <style>
        .article-seo-header {
            position: relative;
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
            min-width: 0;
        }

        .article-seo-header__row {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .article-seo-header__title {
            font-size: inherit;
            font-weight: inherit;
            line-height: 1.3;
            white-space: nowrap;
        }

        .article-seo-header__filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            margin: 0;
            padding: 0;
            border: 1px solid rgb(209 213 219);
            border-radius: 0.375rem;
            background: rgb(255 255 255);
            color: rgb(107 114 128);
            cursor: pointer;
            transition: border-color 0.15s, color 0.15s, background-color 0.15s;
        }

        .article-seo-header__filter-btn:hover:not(:disabled) {
            border-color: rgb(147 197 253);
            color: rgb(37 99 235);
            background: rgb(239 246 255);
        }

        .article-seo-header__filter-btn.is-active {
            border-color: rgb(59 130 246);
            color: rgb(37 99 235);
            background: rgb(219 234 254);
        }

        .article-seo-header__filter-btn:disabled {
            cursor: wait;
            opacity: 0.85;
        }

        .dark .article-seo-header__filter-btn {
            border-color: rgb(75 85 99);
            background: rgb(17 24 39);
            color: rgb(156 163 175);
        }

        .dark .article-seo-header__filter-btn.is-active {
            border-color: rgb(59 130 246);
            color: rgb(147 197 253);
            background: rgb(30 58 138 / 35%);
        }

        .article-seo-header__filter-btn-inner {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .article-seo-header__filter-icon {
            width: 0.875rem;
            height: 0.875rem;
        }

        .article-seo-header__filter-spinner {
            width: 0.875rem !important;
            height: 0.875rem !important;
        }

        .article-seo-header__menu {
            position: absolute;
            top: calc(100% + 0.25rem);
            left: 0;
            z-index: 55;
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
            min-width: 8.5rem;
            padding: 0.25rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            background: rgb(255 255 255);
            box-shadow: 0 10px 25px -12px rgb(0 0 0 / 25%);
        }

        .dark .article-seo-header__menu {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .article-seo-header__menu-item {
            display: block;
            width: 100%;
            margin: 0;
            padding: 0.35rem 0.5rem;
            border: none;
            border-radius: 0.375rem;
            background: transparent;
            font-size: 0.6875rem;
            font-weight: 600;
            line-height: 1.35;
            color: rgb(55 65 81);
            text-align: left;
            cursor: pointer;
        }

        .article-seo-header__menu-item:hover:not(:disabled) {
            background: rgb(243 244 246);
        }

        .article-seo-header__menu-item.is-active {
            background: rgb(219 234 254);
            color: rgb(29 78 216);
        }

        .article-seo-header__menu-item:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .dark .article-seo-header__menu-item {
            color: rgb(209 213 219);
        }

        .dark .article-seo-header__menu-item.is-active {
            background: rgb(30 58 138 / 40%);
            color: rgb(147 197 253);
        }
    </style>
@endonce
