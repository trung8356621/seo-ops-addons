@php
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;

    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    $record = $getRecord();
    $siteId = (int) (KeywordResource::resolveKeywordSiteId($record) ?? 0) ?: null;
@endphp

@include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-item', [
    'keyword' => $record,
    'context' => KeywordItemPresenter::CONTEXT_DICTIONARY,
    'siteId' => $siteId,
    'showCheckbox' => false,
    'showActions' => false,
])
