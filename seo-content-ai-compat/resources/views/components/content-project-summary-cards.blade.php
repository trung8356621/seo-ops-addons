@props([
    'cards' => [],
    'active' => '',
    'wireMethod' => 'applySummaryFilter',
    'ariaLabel' => 'Summary',
    'loadingTargets' => '',
])

{{--
    Shared KPI grid for Content Project ops and the Publishing Queue hub.
    Caller resolves each card's `value` (and optional `count_key` for the
    optimistic counter overlay) before passing `cards` — this component only renders.
--}}
<div
    class="cp-ops-kpi-grid"
    role="group"
    aria-label="{{ $ariaLabel }}"
    wire:loading.class="opacity-60"
    @if ($loadingTargets !== '')
        wire:target="{{ $loadingTargets }}"
    @endif
>
    @foreach ($cards as $card)
        <x-seo-content-ai::content-project-summary-card
            :card="$card['card']"
            :label="$card['label']"
            :hint="$card['hint'] ?? null"
            :value="(int) ($card['value'] ?? 0)"
            :active="$active === $card['card']"
            :count-key="$card['count_key'] ?? null"
            @class(['cp-ops-kpi-card--exception' => ! empty($card['divider_before'])])
            wire:click="{{ $wireMethod }}('{{ $card['filter'] ?? $card['card'] ?? $card['key'] }}')"
        />
    @endforeach
</div>
