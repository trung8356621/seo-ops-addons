@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;

    $record = $getRecord();
    $row = $record->relationLoaded('seoClassification')
        ? $record->seoClassification
        : null;
    $kind = KeywordClassificationVisibility::resolveKind($row);
    $color = KeywordClassificationVisibility::badgeColor($kind);
    $label = KeywordClassificationVisibility::label($kind);
    $percent = KeywordClassificationVisibility::confidencePercent($row);
    $tooltip = KeywordClassificationVisibility::confidenceTooltip($row);
    $seo = KeywordClassificationVisibility::isSeoKeyword($row);
    $anchor = KeywordClassificationVisibility::isAnchorCandidate($row);
@endphp

<div class="space-y-1">
    <span
        @class([
            'inline-flex items-center rounded-md px-1.5 py-0.5 text-[12px] font-semibold',
            'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-400' => $color === 'success',
            'bg-primary-100 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300' => $color === 'primary',
            'bg-info-100 text-info-700 dark:bg-info-500/15 dark:text-info-300' => $color === 'info',
            'bg-warning-100 text-warning-800 dark:bg-warning-500/15 dark:text-warning-400' => $color === 'warning',
            'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400' => $color === 'danger',
            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $color === 'gray',
        ])
    >
        {{ $label }}
    </span>
    @if ($percent !== null)
        <p class="text-[11px] font-medium text-gray-600 dark:text-gray-400" title="{{ $tooltip }}">{{ $percent }}%</p>
    @endif
    <p class="text-[11px] text-gray-600 dark:text-gray-400">
        <span @class(['font-semibold', 'text-success-700 dark:text-success-400' => $seo, 'text-gray-500' => ! $seo])>
            {{ $seo ? __('seo-content-ai::filament.keyword.seo_yes') : __('seo-content-ai::filament.keyword.seo_no') }}
        </span>
        <span class="text-gray-300 dark:text-gray-600">·</span>
        <span @class(['font-semibold', 'text-primary-700 dark:text-primary-300' => $anchor, 'text-gray-500' => ! $anchor])>
            {{ $anchor ? __('seo-content-ai::filament.keyword.anchor_yes') : __('seo-content-ai::filament.keyword.anchor_no') }}
        </span>
    </p>
</div>
