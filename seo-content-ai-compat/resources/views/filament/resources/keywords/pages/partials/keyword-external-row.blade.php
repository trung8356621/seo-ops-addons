@php
    $category = (string) ($row['ui_category'] ?? 'needs_review');
    $statusLabel = match ($category) {
        'managed_cross_site' => __('seo-content-ai::filament.keyword.external_status_managed'),
        'reference' => __('seo-content-ai::filament.keyword.external_status_reference'),
        default => __('seo-content-ai::filament.keyword.external_status_review'),
    };
    $riskLevel = (string) ($row['risk_level'] ?? match ($category) {
        'managed_cross_site' => 'safe',
        'reference' => 'low',
        default => 'review',
    });
    $riskLabel = (string) ($row['risk_level_label'] ?? match ($riskLevel) {
        'safe' => __('seo-content-ai::filament.keyword.risk_level_safe'),
        'low' => __('seo-content-ai::filament.keyword.risk_level_low'),
        default => __('seo-content-ai::filament.keyword.risk_level_review'),
    });
    $riskReason = (string) ($row['risk_reason'] ?? match ($riskLevel) {
        'safe' => __('seo-content-ai::filament.keyword.risk_reason_safe'),
        'low' => __('seo-content-ai::filament.keyword.risk_reason_low'),
        default => __('seo-content-ai::filament.keyword.risk_reason_review'),
    });
    $sourceKeyword = trim((string) ($row['source_keyword_phrase'] ?? ''));
    $sourceArticle = trim((string) ($row['source_article_title'] ?? ''));
    $sourceArticleId = (int) ($row['source_article_id'] ?? 0);
    $sourceArticleEditUrl = $row['source_article_edit_url'] ?? null;
    $isSourceInDraft = ! empty($row['is_source_in_draft']);
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
    $mapId = (int) ($row['map_id'] ?? 0);
@endphp

<tr
    wire:key="keyword-external-row-{{ $mapId }}"
    class="link-triage-row keyword-external-row"
    data-external-category="{{ $category }}"
    data-risk-level="{{ $riskLevel }}"
    data-external-map="{{ $mapId }}"
>
    {{-- Col 1: SOURCE --}}
    <td class="link-triage-td keyword-external-col--source align-top">
        <p class="keyword-external-keyword">{{ $sourceKeyword !== '' ? $sourceKeyword : '—' }}</p>
        @if ($sourceArticle !== '')
            @if ($sourceArticleEditUrl !== null)
                <a
                    href="{{ $sourceArticleEditUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="keyword-external-source-article-link hover:underline font-medium text-primary-600 dark:text-primary-400 block mt-1"
                    title="{{ __('seo-content-ai::filament.keyword.external_open_source_article') }}"
                >
                    {{ $sourceArticle }}
                </a>
            @else
                <p class="keyword-external-meta font-medium mt-1">{{ $sourceArticle }}</p>
            @endif
        @else
            <p class="keyword-external-meta italic text-gray-400 dark:text-gray-500 mt-1">
                {{ __('seo-content-ai::filament.keyword.external_source_article_unresolved') }}
            </p>
        @endif
        @if ($sourceTopic !== '')
            <p class="keyword-external-meta">{{ __('seo-content-ai::filament.keyword.external_source_topic', ['topic' => $sourceTopic]) }}</p>
        @endif
        @if ($sourceSite !== '')
            <p class="keyword-external-meta">{{ $sourceSite }}</p>
        @endif
    </td>

    {{-- Col 2: STATUS / SEMANTIC --}}
    <td class="link-triage-td keyword-external-col--status align-top">
        <div class="flex flex-wrap items-center gap-1.5">
            <span @class([
                'keyword-external-status',
                'keyword-external-status--managed' => $category === 'managed_cross_site',
                'keyword-external-status--reference' => $category === 'reference',
                'keyword-external-status--review' => $category === 'needs_review',
            ]) title="{{ __('seo-content-ai::filament.keyword.external_col_status') }}">{{ $statusLabel }}</span>

            <span @class([
                'keyword-risk-badge',
                'keyword-risk-badge--safe' => $riskLevel === 'safe',
                'keyword-risk-badge--low' => $riskLevel === 'low',
                'keyword-risk-badge--review' => $riskLevel === 'review',
            ]) data-risk-level="{{ $riskLevel }}" title="{{ __('seo-content-ai::filament.keyword.risk_col_risk') }}: {{ $riskLabel }}">{{ $riskLabel }}</span>
        </div>
        @if ($riskReason !== '')
            <p class="keyword-external-meta keyword-risk-reason">{{ $riskReason }}</p>
        @endif
        @if ($destinationKind !== '')
            <p class="keyword-external-meta">{{ $destinationKind }}</p>
        @endif
    </td>

    {{-- Col 3: DESTINATION --}}
    <td class="link-triage-td keyword-external-col--destination align-top">
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

    {{-- Col 4: ACTION --}}
    <td class="link-triage-td keyword-external-col--actions align-top">
        <div class="flex flex-col items-start gap-1.5">
            @if ($sourceArticleEditUrl !== null)
                <a
                    href="{{ $sourceArticleEditUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="link-triage-action-btn link-triage-action-btn--edit inline-flex items-center gap-1 text-xs"
                    title="{{ __('seo-content-ai::filament.keyword.external_open_source_article') }}"
                >
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                    <span>{{ __('seo-content-ai::filament.keyword.external_open_source_article') }}</span>
                </a>
            @endif

            @if ($riskLevel === 'review')
                @if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canMutateInSeoPanel())
                    @if ($isSourceInDraft)
                        <button
                            type="button"
                            disabled
                            class="link-triage-action-btn link-triage-action-btn--in-draft inline-flex items-center gap-1 text-xs opacity-75 cursor-not-allowed"
                            title="{{ __('seo-content-ai::filament.article_list.already_in_draft') }}"
                        >
                            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                            <span>{{ __('seo-content-ai::filament.article_list.already_in_draft') }}</span>
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="pushToDraft({{ $mapId }})"
                            wire:loading.attr="disabled"
                            wire:target="pushToDraft({{ $mapId }})"
                            class="link-triage-action-btn link-triage-action-btn--push-draft inline-flex items-center gap-1 text-xs text-amber-700 hover:text-amber-800 bg-amber-50 hover:bg-amber-100 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700/50"
                            title="{{ __('seo-content-ai::filament.keyword.external_push_to_draft') }}"
                        >
                            <x-filament::icon icon="heroicon-m-arrow-right-circle" class="h-3.5 w-3.5" />
                            <span>{{ __('seo-content-ai::filament.keyword.external_push_to_draft') }}</span>
                        </button>
                    @endif
                @endif
            @endif
        </div>
    </td>
</tr>
