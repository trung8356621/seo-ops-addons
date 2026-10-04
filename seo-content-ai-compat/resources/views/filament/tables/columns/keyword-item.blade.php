@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;

    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    $record = $getRecord();
    $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0) ?: null;
@endphp

@include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-item', [
    'keyword' => $record,
    'context' => KeywordItemPresenter::CONTEXT_DICTIONARY,
    'siteId' => $siteId,
    'showCheckbox' => false,
    'showActions' => false,
])
