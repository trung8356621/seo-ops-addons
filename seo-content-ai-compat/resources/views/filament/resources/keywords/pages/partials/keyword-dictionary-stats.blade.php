@php
    $stats = $this->getDictionaryStats();
    $activeFilter = $this->dictionaryStatFilter;
    $mode = (string) ($stats['mode'] ?? 'default');
    $cards = $mode === 'no_topic'
        ? [
            'no_topic' => [
                'label' => __('seo-content-ai::filament.keyword.stat_no_topic'),
                'value' => $stats['no_topic'] ?? $stats['total'] ?? 0,
                'tone' => 'violet',
                'icon' => 'heroicon-o-queue-list',
                'clickable' => false,
            ],
            'errors' => [
                'label' => __('seo-content-ai::filament.keyword.stat_errors'),
                'value' => $stats['errors'] ?? 0,
                'tone' => 'danger',
                'icon' => 'heroicon-o-x-circle',
                'clickable' => true,
            ],
        ]
        : [
            'active' => [
                'label' => __('seo-content-ai::filament.keyword.stat_active'),
                'value' => $stats['active'] ?? 0,
                'tone' => 'success',
                'icon' => 'heroicon-o-check-circle',
                'clickable' => true,
            ],
            'errors' => [
                'label' => __('seo-content-ai::filament.keyword.stat_errors'),
                'value' => $stats['errors'] ?? 0,
                'tone' => 'danger',
                'icon' => 'heroicon-o-x-circle',
                'clickable' => true,
            ],
        ];
@endphp

<div class="keyword-dictionary-stats" aria-label="{{ __('seo-content-ai::filament.keyword.dictionary_stats_label') }}">
    @foreach ($cards as $statKey => $stat)
        @if ($stat['clickable'] ?? true)
            <button
                type="button"
                wire:click="applyDictionaryStatFilter('{{ $statKey }}')"
                wire:key="dictionary-stat-{{ $statKey }}-{{ $mode }}"
                @class([
                    'keyword-dictionary-stat-card',
                    'keyword-dictionary-stat-card--' . ($stat['tone'] ?? 'violet'),
                    'is-active' => $activeFilter === $statKey,
                ])
                aria-pressed="{{ $activeFilter === $statKey ? 'true' : 'false' }}"
                title="{{ __('seo-content-ai::filament.keyword.stat_filter_hint', ['label' => $stat['label']]) }}"
            >
                <div class="keyword-dictionary-stat-card__wave" aria-hidden="true"></div>
                <div class="keyword-dictionary-stat-card__top">
                    <span @class([
                        'keyword-dictionary-stat-card__icon',
                        'keyword-dictionary-stat-card__icon--' . ($stat['tone'] ?? 'violet'),
                    ])>
                        <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                    </span>
                </div>
                <p class="keyword-dictionary-stat-card__label">{{ $stat['label'] }}</p>
                <p class="keyword-dictionary-stat-card__value">{{ number_format((int) ($stat['value'] ?? 0)) }}</p>
            </button>
        @else
            <div
                wire:key="dictionary-stat-{{ $statKey }}-{{ $mode }}"
                @class([
                    'keyword-dictionary-stat-card',
                    'keyword-dictionary-stat-card--' . ($stat['tone'] ?? 'violet'),
                    'is-active' => true,
                ])
                title="{{ $stat['label'] }}"
            >
                <div class="keyword-dictionary-stat-card__wave" aria-hidden="true"></div>
                <div class="keyword-dictionary-stat-card__top">
                    <span @class([
                        'keyword-dictionary-stat-card__icon',
                        'keyword-dictionary-stat-card__icon--' . ($stat['tone'] ?? 'violet'),
                    ])>
                        <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                    </span>
                </div>
                <p class="keyword-dictionary-stat-card__label">{{ $stat['label'] }}</p>
                <p class="keyword-dictionary-stat-card__value">{{ number_format((int) ($stat['value'] ?? 0)) }}</p>
            </div>
        @endif
    @endforeach
</div>
