@props([
    'card' => 'total',
    'label' => '',
    'value' => 0,
    'active' => false,
    'countKey' => null,
    'hint' => null,
])

@php
    $accent = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter::summaryAccent((string) $card);
    $live = filled($countKey);
    $title = filled($hint) ? (string) $hint : ((string) $label).': '.(int) $value;
@endphp

<button
    type="button"
    {{ $attributes->class([
        'cp-ops-kpi-card',
        'is-active' => $active,
        'accent-'.$accent['key'],
    ]) }}
    x-bind:class="{
        'cp-ops-kpi-card--pulse': {{ $live ? "!!cardPulse['{$countKey}']" : 'false' }},
    }"
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    aria-label="{{ $label }}: {{ (int) $value }}"
    title="{{ $title }}"
>
    <span class="cp-ops-kpi-card__top">
        <span class="cp-ops-kpi-card__label">{{ $label }}</span>
        <x-filament::icon :icon="$accent['icon']" class="cp-ops-kpi-card__icon" />
    </span>
    @if ($live)
        <span
            class="cp-ops-kpi-card__value-wrap"
            aria-live="polite"
            x-bind:aria-label="counterAria('{{ $countKey }}', @js($label))"
        >
            <span
                class="cp-ops-kpi-card__value-stack"
                x-bind:class="{ 'is-animating': !!counterAnimating['{{ $countKey }}'] }"
            >
                <span
                    class="cp-ops-kpi-card__value cp-ops-kpi-card__value--out"
                    x-text="previousCounts['{{ $countKey }}'] ?? displayCount('{{ $countKey }}')"
                    x-show="!!counterAnimating['{{ $countKey }}']"
                    x-cloak
                ></span>
                <span
                    class="cp-ops-kpi-card__value cp-ops-kpi-card__value--in"
                    x-text="displayCount('{{ $countKey }}')"
                >{{ (int) $value }}</span>
            </span>
        </span>
    @else
        <span class="cp-ops-kpi-card__value">{{ (int) $value }}</span>
    @endif
</button>
