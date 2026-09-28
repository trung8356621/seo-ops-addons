@php
    $category = (string) ($row['ui_category'] ?? 'needs_review');
    $statusLabel = match ($category) {
        'managed_cross_site' => __('seo-content-ai::filament.keyword.external_status_managed'),
        'reference' => __('seo-content-ai::filament.keyword.external_status_reference'),
        default => __('seo-content-ai::filament.keyword.external_status_review'),
    };
    $sourceKeyword = trim((string) ($row['source_keyword_phrase'] ?? ''));
    $sourceArticle = trim((string) ($row['source_article_title'] ?? ''));
    $sourceTopic = trim((string) ($row['source_topic_name'] ?? ''));
    $sourceSite = trim((string) ($row['source_site_domain'] ?? ''));
    $targetSite = trim((string) ($row['target_site_domain'] ?? ''));
    $targetSiteId = (int) ($row['target_site_id'] ?? 0);
    $targetArticle = trim((string) ($row['target_article_title'] ?? ''));
    $targetKeyword = trim((string) ($row['target_keyword_phrase'] ?? ''));
    $externalUrl = trim((string) ($row['target_external_url'] ?? ''));
    $externalHost = '';
    if ($externalUrl !== '') {
        $host = parse_url($externalUrl, PHP_URL_HOST);
        $externalHost = is_string($host) ? $host : $externalUrl;
    }
    $destinationKind = trim((string) ($row['destination_kind'] ?? ''));
@endphp

<tr
    wire:key="keyword-external-row-{{ (int) ($row['map_id'] ?? 0) }}"
    class="link-triage-row keyword-external-row"
    data-external-category="{{ $category }}"
    data-external-map="{{ (int) ($row['map_id'] ?? 0) }}"
>
    <td class="link-triage-td align-top">
        <p class="keyword-external-keyword">{{ $sourceKeyword !== '' ? $sourceKeyword : '—' }}</p>
        @if ($sourceArticle !== '')
            <p class="keyword-external-meta">{{ $sourceArticle }}</p>
        @endif
        @if ($sourceTopic !== '')
            <p class="keyword-external-meta">{{ __('seo-content-ai::filament.keyword.external_source_topic', ['topic' => $sourceTopic]) }}</p>
        @endif
        @if ($sourceSite !== '')
            <p class="keyword-external-meta">{{ $sourceSite }}</p>
        @endif
    </td>
    <td class="link-triage-td align-top">
        <span @class([
            'keyword-external-status',
            'keyword-external-status--managed' => $category === 'managed_cross_site',
            'keyword-external-status--reference' => $category === 'reference',
            'keyword-external-status--review' => $category === 'needs_review',
        ])>{{ $statusLabel }}</span>
        @if ($destinationKind !== '')
            <p class="keyword-external-meta">{{ $destinationKind }}</p>
        @endif
    </td>
    <td class="link-triage-td align-top">
        @if ($category === 'managed_cross_site')
            <p class="keyword-external-destination">
                {{ $targetSite !== '' ? $targetSite : ($targetSiteId > 0 ? __('seo-content-ai::filament.keyword.external_managed_site', ['id' => $targetSiteId]) : __('seo-content-ai::filament.keyword.external_site_unknown')) }}
            </p>
            <p class="keyword-external-meta">
                @if ($targetArticle !== '')
                    {{ $targetArticle }}
                @else
                    {{ __('seo-content-ai::filament.keyword.external_target_article_unresolved') }}
                @endif
            </p>
            <p class="keyword-external-meta">
                @if ($targetKeyword !== '')
                    {{ $targetKeyword }}
                @else
                    {{ __('seo-content-ai::filament.keyword.external_target_keyword_unresolved') }}
                @endif
            </p>
        @else
            <p class="keyword-external-destination">
                {{ $externalHost !== '' ? $externalHost : '—' }}
            </p>
            @if ($externalUrl !== '')
                <p class="keyword-external-meta keyword-external-url">{{ $externalUrl }}</p>
            @endif
            <p class="keyword-external-meta">
                {{ $category === 'reference'
                    ? __('seo-content-ai::filament.keyword.external_reference_note')
                    : __('seo-content-ai::filament.keyword.external_review_note') }}
            </p>
        @endif
    </td>
</tr>
