@php
    $intent = strtolower(trim((string) ($intent ?? '')));
    $coverage = strtolower(trim((string) ($coverage ?? 'unknown')));
    $source = strtolower(trim((string) ($canonicalSource ?? 'auto')));
    $state = strtolower(trim((string) ($state ?? 'active')));
    $intentLabel = $intent !== '' ? ucfirst($intent) : '';
    $coverageLabel = $coverage !== '' && $coverage !== 'unknown' ? ucfirst($coverage) : '';
@endphp

<div class="cluster-tag-row">
    @if ($intentLabel !== '')
        <span class="cluster-tag cluster-tag--intent">{{ $intentLabel }}</span>
    @endif
    @if ($coverageLabel !== '')
        <span class="cluster-tag cluster-tag--coverage">{{ __('seo-content-ai::filament.keyword.topic_tag_coverage', ['level' => $coverageLabel]) }}</span>
    @endif
    @if ($state === 'planned' || ((int) ($keywordCount ?? 0)) === 0 && $source === 'manual')
        <span class="cluster-tag cluster-tag--planned">{{ __('seo-content-ai::filament.keyword.topic_tag_planned') }}</span>
    @endif
    @if ($source === 'manual')
        <span class="cluster-tag cluster-tag--manual">{{ __('seo-content-ai::filament.keyword.topic_tag_manual') }}</span>
    @elseif ($source === 'auto' && $state !== 'planned')
        <span class="cluster-tag cluster-tag--auto">{{ __('seo-content-ai::filament.keyword.topic_tag_auto') }}</span>
    @endif
</div>
