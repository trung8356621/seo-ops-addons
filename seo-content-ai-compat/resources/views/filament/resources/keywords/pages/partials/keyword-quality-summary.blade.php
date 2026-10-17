@php
    $quality = $this->keywordQualitySummary();
    $selected = $this->qualityCardFilter;
@endphp

@if (is_array($quality))
    <div class="keyword-dictionary-stats" aria-label="{{ __('seo-content-ai::filament.keyword.quality_summary') }}">
        <button
            type="button"
            wire:click="applyQualityCardFilter('total')"
            wire:key="quality-card-total"
            @class(['keyword-dictionary-stat-card', 'keyword-dictionary-stat-card--violet', 'is-active' => $selected === null && $this->dictionaryStatFilter === null])
            aria-pressed="{{ $selected === null && $this->dictionaryStatFilter === null ? 'true' : 'false' }}"
        >
            <p class="keyword-dictionary-stat-card__label">{{ __('seo-content-ai::filament.keyword.quality_total') }}</p>
            <p class="keyword-dictionary-stat-card__value">{{ number_format((int) $quality['total']) }}</p>
        </button>
        <button
            type="button"
            wire:click="applyQualityCardFilter('tagged')"
            wire:key="quality-card-tagged"
            @class(['keyword-dictionary-stat-card', 'is-active' => $selected === 'tagged'])
            aria-pressed="{{ $selected === 'tagged' ? 'true' : 'false' }}"
        >
            <p class="keyword-dictionary-stat-card__label">{{ __('seo-content-ai::filament.keyword.quality_unique_tagged') }}</p>
            <p class="keyword-dictionary-stat-card__value">{{ number_format((int) $quality['unique_tagged']) }}</p>
            <p class="keyword-dictionary-stat-card__percent">{{ $quality['unique_tagged_percent'] }}%</p>
        </button>
        <button
            type="button"
            wire:click="applyQualityCardFilter('excluded')"
            wire:key="quality-card-excluded"
            @class(['keyword-dictionary-stat-card', 'is-active' => $selected === 'excluded'])
            aria-pressed="{{ $selected === 'excluded' ? 'true' : 'false' }}"
        >
            <p class="keyword-dictionary-stat-card__label">{{ __('seo-content-ai::filament.keyword.quality_unique_excluded') }}</p>
            <p class="keyword-dictionary-stat-card__value">{{ number_format((int) $quality['unique_excluded']) }}</p>
            <p class="keyword-dictionary-stat-card__percent">{{ $quality['unique_excluded_percent'] }}%</p>
        </button>
        @foreach ($quality['tags'] as $tag)
            <button
                type="button"
                wire:click="applyQualityCardFilter('{{ $tag['code'] }}')"
                wire:key="quality-card-{{ $tag['code'] }}"
                @class(['keyword-dictionary-stat-card', 'is-active' => $selected === $tag['code']])
                aria-pressed="{{ $selected === $tag['code'] ? 'true' : 'false' }}"
            >
                <p class="keyword-dictionary-stat-card__label">{{ $tag['label'] }}</p>
                <p class="keyword-dictionary-stat-card__value">{{ number_format((int) $tag['count']) }}</p>
                <p class="keyword-dictionary-stat-card__percent">{{ $tag['percent'] }}%</p>
            </button>
        @endforeach
    </div>
    @if (! empty($quality['scan_stale']))
        <p class="keyword-quality-scan-status">
            {{ __('seo-content-ai::filament.keyword.quality_scan_stale') }}
            {{ __('seo-content-ai::filament.keyword.quality_scan_status', [
                'status' => $quality['scan_status'],
                'processed' => $quality['scan_processed'],
                'total' => $quality['scan_total'],
            ]) }}
        </p>
    @elseif (($quality['scan_status'] ?? null) !== null)
        <p class="keyword-quality-scan-status">
            {{ __('seo-content-ai::filament.keyword.quality_scan_status', [
                'status' => $quality['scan_status'],
                'processed' => $quality['scan_processed'],
                'total' => $quality['scan_total'],
            ]) }}
            @if (! empty($quality['scan_error']))
                {{ $quality['scan_error'] }}
            @endif
        </p>
    @endif
@endif
