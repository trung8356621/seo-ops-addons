@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    use Omnichannel\Addons\SearchFoundation\Support\KeywordLinkDetailPanelPresenter;
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;

    $presenter = app(KeywordLinkDetailPanelPresenter::class);
    $resolver = app(KeywordTagResolver::class);
    $viewSiteId = (int) (KeywordResource::resolveKeywordSiteId($record) ?? 0);
    $linkItems = $presenter->buildItems($record, $viewSiteId > 0 ? $viewSiteId : null);
    $linkedArticles = collect($presenter->buildLinkedSourceArticles($record, $viewSiteId > 0 ? $viewSiteId : null));
    $internalLinks = collect($linkItems)
        ->filter(static fn (array $item): bool => ($item['link_type'] ?? '') === 'internal')
        ->values();
    $clusterLabel = $resolver->clusterLabel($record);
    $classification = $record->seoClassification;
    $kind = KeywordClassificationVisibility::resolveKind($classification);
    $siteDomainOption = $viewSiteId > 0 ? (KeywordResource::siteSelectOptions()[$viewSiteId] ?? null) : null;
    $siteDomain = is_string($siteDomainOption) && $siteDomainOption !== ''
        ? $siteDomainOption
        : ($record->linkMaps->first(fn ($m) => (int) ($m->sourceArticle?->site_id ?? 0) === $viewSiteId)?->sourceArticle?->site?->domain
            ?? $record->mainArticlesForSite($viewSiteId)->first()?->site?->domain
            ?? '—');
@endphp

<div class="keyword-dictionary-drawer">
    <p class="keyword-dictionary-drawer__domain">{{ $siteDomain }}</p>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.operational_tags') }}
            </h3>
        </div>
        <div class="flex flex-wrap gap-1">
            @forelse ($resolver->displayTags($record) as $tag)
                <span class="{{ $tag['badge_class'] }}">{{ $tag['label'] }}</span>
            @empty
                <span class="text-[12px] text-gray-400">—</span>
            @endforelse
        </div>
    </section>

    <div class="keyword-dictionary-drawer__mini-stats">
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.cluster_label') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ $clusterLabel }}</span>
        </div>
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.linked_articles') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ number_format((int) ($record->linked_articles_count ?? 0)) }}</span>
        </div>
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.internal_links_short') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ number_format((int) ($record->site_links_count ?? 0)) }}</span>
        </div>
    </div>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.technical_details') }}
            </h3>
        </div>
        <div class="keyword-dictionary-drawer__mini-stats">
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.classification') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">{{ KeywordClassificationVisibility::label($kind) }}</span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.confidence') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">
                    @php $pct = KeywordClassificationVisibility::confidencePercent($classification); @endphp
                    {{ $pct === null ? '—' : $pct.'%' }}
                </span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.advanced_intent') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">{{ (string) ($classification?->seo_intent ?: '—') }}</span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.seo_usable') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">
                    {{ KeywordClassificationVisibility::isSeoKeyword($classification)
                        ? __('seo-content-ai::filament.keyword.filter_yes')
                        : __('seo-content-ai::filament.keyword.filter_no') }}
                </span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.anchor_candidate') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">
                    {{ KeywordClassificationVisibility::isAnchorCandidate($classification)
                        ? __('seo-content-ai::filament.keyword.filter_yes')
                        : __('seo-content-ai::filament.keyword.filter_no') }}
                </span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.advanced_source') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">{{ (string) ($classification?->source_kind ?: '—') }}</span>
            </div>
            <div class="keyword-dictionary-drawer__mini-stat">
                <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.legacy_type') }}</span>
                <span class="keyword-dictionary-drawer__mini-stat-value">{{ (string) ($record->type ?: '—') }}</span>
            </div>
        </div>
    </section>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.drawer_linked_articles_heading') }}
            </h3>
        </div>
        @if ($linkedArticles->isEmpty())
            <p class="keyword-dictionary-drawer__empty">—</p>
        @else
            <ul class="keyword-dictionary-drawer__list keyword-dictionary-drawer__list--scrollable">
                @foreach ($linkedArticles as $article)
                    <li class="keyword-dictionary-drawer__list-item">
                        <div class="keyword-dictionary-drawer__list-body">
                            @if (! empty($article['wp_url']))
                                <a
                                    href="{{ $article['wp_url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="keyword-dictionary-drawer__list-title text-indigo-600 hover:text-indigo-500 dark:text-indigo-300"
                                >
                                    {{ $article['title'] }}
                                </a>
                            @else
                                <p class="keyword-dictionary-drawer__list-title">{{ $article['title'] }}</p>
                            @endif
                        </div>
                        <div class="keyword-dictionary-drawer__list-aside">
                            @if (! empty($article['is_focus']))
                                <span class="keyword-dictionary-drawer__list-badge ws-badge ws-badge--info ws-badge--status">
                                    {{ __('seo-content-ai::filament.keyword.focus_short') }}
                                </span>
                            @else
                                <span class="keyword-dictionary-drawer__list-badge ws-badge ws-badge--success ws-badge--status">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" />
                                    {{ __('seo-content-ai::filament.keyword.stat_active') }}
                                </span>
                            @endif

                            <div class="keyword-dictionary-drawer__card-actions">
                                @if (! empty($article['edit_url']))
                                    <a
                                        href="{{ $article['edit_url'] }}"
                                        class="keyword-dictionary-drawer__project-icon keyword-dictionary-drawer__project-icon--edit"
                                        title="{{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}"
                                        aria-label="{{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}"
                                    >
                                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                                    </a>
                                @endif

                                @if ($article['can_assign_content_project'] ?? false)
                                    <button
                                        type="button"
                                        data-assign-article="{{ (int) ($article['id'] ?? 0) }}"
                                        data-article-site-id="{{ (int) ($article['site_id'] ?? 0) }}"
                                        class="keyword-dictionary-drawer__project-icon keyword-dictionary-drawer__project-icon--assign"
                                        title="{{ ! empty($article['in_draft']) ? __('seo-content-ai::filament.article_list.already_in_draft') : __('seo-content-ai::filament.article_list.add_to_draft') }}"
                                        aria-label="{{ __('seo-content-ai::filament.article_list.add_to_draft') }}"
                                    >
                                        <x-filament::icon icon="heroicon-o-folder-plus" class="h-4 w-4" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.drawer_internal_links_heading') }}
            </h3>
        </div>
        @if ($internalLinks->isEmpty())
            <p class="keyword-dictionary-drawer__empty">—</p>
        @else
            <ul class="keyword-dictionary-drawer__list keyword-dictionary-drawer__list--scrollable">
                @foreach ($internalLinks as $item)
                    <li class="keyword-dictionary-drawer__list-item">
                        <div class="keyword-dictionary-drawer__list-body">
                            <p class="keyword-dictionary-drawer__list-title">{{ $item['source_title'] ?? '—' }}</p>
                            @if (! empty($item['target_url']))
                                <a
                                    href="{{ $item['target_url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="keyword-dictionary-drawer__list-meta text-indigo-600 hover:text-indigo-500 dark:text-indigo-300"
                                >
                                    {{ $item['target_label'] ?? $item['target_url'] }}
                                </a>
                            @else
                                <p class="keyword-dictionary-drawer__list-meta">—</p>
                            @endif
                        </div>
                        <div class="keyword-dictionary-drawer__list-aside">
                            <span class="keyword-dictionary-drawer__list-badge ws-badge ws-badge--success ws-badge--status">
                                <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" />
                                {{ __('seo-content-ai::filament.keyword.link_status_active') }}
                            </span>
                            <div class="keyword-dictionary-drawer__card-actions">
                                @if (! empty($item['resolved_edit_url']))
                                    <a
                                        href="{{ $item['resolved_edit_url'] }}"
                                        class="keyword-dictionary-drawer__project-icon keyword-dictionary-drawer__project-icon--edit"
                                        title="{{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}"
                                        aria-label="{{ __('seo-content-ai::filament.keyword.drawer_edit_article') }}"
                                    >
                                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                                    </a>
                                @endif
                                @if ($item['can_add_to_draft'] ?? false)
                                    <button
                                        type="button"
                                        data-assign-article="{{ (int) ($item['resolved_article_id'] ?? 0) }}"
                                        data-article-site-id="{{ (int) ($item['resolved_site_id'] ?? 0) }}"
                                        class="keyword-dictionary-drawer__project-icon keyword-dictionary-drawer__project-icon--assign"
                                        title="{{ __('seo-content-ai::filament.article_list.add_to_draft') }}"
                                        aria-label="{{ __('seo-content-ai::filament.article_list.add_to_draft') }}"
                                    >
                                        <x-filament::icon icon="heroicon-o-folder-plus" class="h-4 w-4" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
